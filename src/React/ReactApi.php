<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\React;

use ErrorException;
use React\EventLoop\LoopInterface;
use Recoil\Exception\TimeoutException;
use Recoil\Kernel\Api;
use Recoil\Kernel\ApiTrait;
use Recoil\Kernel\KernelStrand;
use Recoil\Kernel\Strand;

/**
 * A kernel API based on the React event loop.
 */
final class ReactApi implements Api
{
    /**
     * @param LoopInterface $eventLoop The event loop.
     */
    public function __construct(
        LoopInterface $eventLoop,
        StreamQueue $streamQueue = null
    ) {
        $this->eventLoop = $eventLoop;
        $this->streamQueue = $streamQueue ?: new StreamQueue($eventLoop);
    }

    /**
     * Force the current strand to cooperate.
     *
     * @see Recoil::cooperate() for the full specification.
     *
     * @param KernelStrand $strand The strand executing the API call.
     */
    public function cooperate(KernelStrand $strand)
    {
        $this->eventLoop->futureTick(
            static function () use ($strand) {
                $strand->send();
            }
        );
    }

    /**
     * Suspend the current strand for a fixed interval.
     *
     * @see Recoil::sleep() for the full specification.
     *
     * @param KernelStrand $strand   The strand executing the API call.
     * @param float        $interval The interval to wait, in seconds.
     */
    public function sleep(KernelStrand $strand, float $seconds)
    {
        if ($seconds > 0) {
            $timer = $this->eventLoop->addTimer(
                $seconds,
                static function () use ($strand) {
                    $strand->send();
                }
            );

            $strand->setTerminator(
                static function () use ($timer) {
                    $timer->cancel();
                }
            );
        } else {
            $this->eventLoop->futureTick(
                static function () use ($strand) {
                    $strand->send();
                }
            );
        }
    }

    /**
     * Execute a coroutine with a cap on execution time.
     *
     * @see Recoil::timeout() for the full specification.
     *
     * @param KernelStrand $strand    The strand executing the API call.
     * @param float        $timeout   The interval to allow for execution, in seconds.
     * @param mixed        $coroutine The coroutine to execute.
     */
    public function timeout(KernelStrand $strand, float $seconds, $coroutine)
    {
        $substrand = $strand->kernel()->execute($coroutine);
        assert($substrand instanceof KernelStrand);

        (new StrandTimeout($this->eventLoop, $seconds, $substrand))->await($strand);
    }

    /**
     * Read data from a stream.
     *
     * @see Recoil::read() for the full specification.
     *
     * @param KernelStrand $strand    The strand executing the API call.
     * @param resource     $stream    A readable stream resource.
     * @param int          $minLength The minimum number of bytes to read.
     * @param int          $maxLength The maximum number of bytes to read.
     */
    public function read(
        KernelStrand $strand,
        $stream,
        int $minLength = PHP_INT_MAX,
        int $maxLength = PHP_INT_MAX
    ) {
        assert($minLength >= 1, 'minimum length must be at least one');
        assert($minLength <= $maxLength, 'minimum length must not exceed maximum length');

        $buffer = '';
        $done = null;
        $done = $this->streamQueue->read(
            $stream,
            function ($stream) use (
                $strand,
                &$minLength,
                &$maxLength,
                &$done,
                &$buffer
            ) {
                $chunk = @\fread(
                    $stream,
                    $maxLength < self::MAX_READ_LENGTH
                        ? $maxLength
                        : self::MAX_READ_LENGTH
                );

                if ($chunk === false) {
                    // @codeCoverageIgnoreStart
                    $done();
                    $error = \error_get_last();
                    $strand->throw(
                        new ErrorException(
                            $error['message'],
                            $error['type'],
                            1, // severity
                            $error['file'],
                            $error['line']
                        )
                    );
                    // @codeCoverageIgnoreEnd
                } elseif ($chunk === '') {
                    $done();
                    $strand->send($buffer);
                } else {
                    $buffer .= $chunk;
                    $length = \strlen($chunk);

                    if ($length >= $minLength || $length === $maxLength) {
                        $done();
                        $strand->send($buffer);
                    } else {
                        $minLength -= $length;
                        $maxLength -= $length;
                    }
                }
            }
        );

        $strand->setTerminator($done);
    }

    /**
     * Write data to a stream.
     *
     * @see Recoil::write() for the full specification.
     *
     * @param KernelStrand $strand The strand executing the API call.
     * @param resource     $stream A writable stream resource.
     * @param string       $buffer The data to write to the stream.
     * @param int          $length The maximum number of bytes to write.
     */
    public function write(
        KernelStrand $strand,
        $stream,
        string $buffer,
        int $length = PHP_INT_MAX
    ) {
        $bufferLength = \strlen($buffer);

        if ($bufferLength < $length) {
            $length = $bufferLength;
        }

        $done = null;
        $done = $this->streamQueue->write(
            $stream,
            function ($stream) use (
                $strand,
                &$done,
                &$buffer,
                &$length
            ) {
                $bytes = @\fwrite($stream, $buffer, $length);

                if ($bytes === false) {
                    // @codeCoverageIgnoreStart
                    $done();
                    $error = \error_get_last();
                    $strand->throw(
                        new ErrorException(
                            $error['message'],
                            $error['type'],
                            1, // severity
                            $error['file'],
                            $error['line']
                        )
                    );
                    // @codeCoverageIgnoreEnd
                } elseif ($bytes === $length) {
                    $done();
                    $strand->send();
                } else {
                    $length -= $bytes;
                    $buffer = \substr($buffer, $bytes);
                }
            }
        );

        $strand->setTerminator($done);
    }

    /**
     * Monitor multiple streams, waiting until one or more becomes "ready" for
     * reading or writing.
     *
     * This is operation is COOPERATIVE.
     * This operation is NON-STANDARD.
     *
     * This operation is directly analogous to {@see stream_select()}, except
     * that it allows other strands to execute while waiting for the streams.
     *
     * A stream is considered ready for reading when a call to {@see fread()}
     * will not block, and likewise ready for writing when {@see fwrite()} will
     * not block.
     *
     * The calling strand is resumed with a 2-tuple containing arrays of the
     * ready streams. This allows the result to be unpacked with {@see list()}.
     *
     * A given stream may be monitored by multiple strands simultaneously, but
     * only one of the strands is resumed when the stream becomes ready. There
     * is no guarantee which strand will be resumed.
     *
     * Any stream that has an in-progress call to {@see Api::read()} or
     * {@see Api::write()} will not be included in the resulting tuple until
     * those operations are complete.
     *
     * If no streams become ready within the specified time, the calling strand
     * is resumed with a {@see TimeoutException}.
     *
     * If no streams are provided, the calling strand is resumed immediately.
     *
     * @param Strand             $strand  The strand executing the API call.
     * @param array<stream>|null $read    Streams monitored until they become "readable" (null = none).
     * @param array<stream>|null $write   Streams monitored until they become "writable" (null = none).
     * @param float|null         $timeout The maximum amount of time to wait, in seconds (null = forever).
     *
     * @return null
     */
    public function select(
        KernelStrand $strand,
        array $read = null,
        array $write = null,
        float $timeout = null
    ) {
        if (empty($read) && empty($write)) {
            $strand->send([[], []]);

            return;
        }

        $context = new class()
        {
            public $strand;
            public $read;
            public $write;
            public $timeout;
            public $timer;
            public $done = [];
        };

        $context->strand = $strand;
        $context->read = $read;
        $context->write = $write;
        $context->timeout = $timeout;

        if ($context->read !== null) {
            foreach ($context->read as $stream) {
                $context->done[] = $this->streamQueue->read(
                    $stream,
                    function ($stream) use ($context) {
                        foreach ($context->done as $done) {
                            $done();
                        }

                        if ($context->timer) {
                            $context->timer->cancel();
                        }

                        $context->strand->send([[$stream], []]);
                    }
                );
            }
        }

        if ($context->write !== null) {
            foreach ($context->write as $stream) {
                $context->done[] = $this->streamQueue->write(
                    $stream,
                    function ($stream) use ($context) {
                        foreach ($context->done as $done) {
                            $done();
                        }

                        if ($context->timer) {
                            $context->timer->cancel();
                        }

                        $context->strand->send([[], [$stream]]);
                    }
                );
            }
        }

        $context->strand->setTerminator(function () use ($context) {
            foreach ($context->done as $done) {
                $done();
            }

            if ($context->timer) {
                $context->timer->cancel();
            }
        });

        if ($context->timeout !== null) {
            $context->timer = $this->eventLoop->addTimer(
                $context->timeout,
                function () use ($context) {
                    foreach ($context->done as $done) {
                        $done();
                    }

                    $context->strand->throw(
                        new TimeoutException($context->timeout)
                    );
                }
            );
        }
    }

    /**
     * Get the event loop.
     *
     * This is operation is NON-COOPERATIVE.
     * This operation is NON-STANDARD.
     *
     * The caller is resumed with the event loop used by this API.
     *
     * @param Strand $strand The strand executing the API call.
     */
    public function eventLoop(KernelStrand $strand)
    {
        $strand->send($this->eventLoop);
    }

    use ApiTrait;

    const MAX_READ_LENGTH = 32768;

    /**
     * @var LoopInterface The event loop.
     */
    private $eventLoop;

    /**
     * @var StreamQueue The stream queue, used to control sequential access
     *                  to streams.
     */
    private $streamQueue;
}
