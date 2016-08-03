<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\React;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use Recoil\Exception\TerminatedException;
use Recoil\Kernel\Api;
use Recoil\Kernel\Exception\KernelPanicException;
use Recoil\Kernel\Exception\KernelStoppedException;
use Recoil\Kernel\Exception\StrandException;
use Recoil\Kernel\Kernel;
use Recoil\Kernel\Strand;
use SplQueue;
use Throwable;

/**
 * A Recoil coroutine kernel based on a ReactPHP event loop.
 */
final class ReactKernel implements Kernel
{
    /**
     * Execute a coroutine on a new kernel.
     *
     * This is a convenience method for:
     *
     *     $kernel = new Kernel($eventLoop);
     *     $kernel->executeSync($coroutine);
     *
     * @param mixed              $coroutine The coroutine to execute.
     * @param LoopInterface|null $eventLoop The event loop to use (null = default).
     *
     * @return mixed                  The return value of the coroutine.
     * @throws Throwable              The exception produced by the coroutine.
     * @throws TerminatedException    The strand has been terminated.
     * @throws KernelStoppedException The kernel was stopped before the strand exited.
     * @throws KernelPanicException   Some other strand has caused a kernel panic.
     */
    public static function start($coroutine, LoopInterface $eventLoop = null)
    {
        $kernel = self::create($eventLoop);

        return $kernel->executeSync($coroutine);
    }

    /**
     * Create a new kernel.
     *
     * @param LoopInterface|null $eventLoop The event loop to use (null = default).
     */
    public static function create(LoopInterface $eventLoop = null) : self
    {
        if ($eventLoop === null) {
            $eventLoop = Factory::create();
        }

        return new self(
            $eventLoop,
            new ReactApi($eventLoop)
        );
    }

    /**
     * Run the kernel until all strands exit, the kernel is stopped or a kernel
     * panic occurs.
     *
     * A kernel panic occurs when a strand throws an exception that is not
     * handled by the kernel's exception handler.
     *
     * This method returns immediately if the kernel is already running.
     *
     * @see Kernel::setExceptionHandler()
     *
     * @throws KernelPanicException A strand has caused a kernel panic.
     */
    public function run()
    {
        if ($this->state !== self::STATE_STOPPED) {
            return;
        } elseif (!$this->panicExceptions->isEmpty()) {
            throw $this->panicExceptions->dequeue();
        }

        try {
            $this->state = self::STATE_RUNNING;
            $this->eventLoop->run();
        } catch (Throwable $e) {
            $this->throw($e);
        } finally {
            $this->state = self::STATE_STOPPED;
        }

        if (!$this->panicExceptions->isEmpty()) {
            throw $this->panicExceptions->dequeue();
        }
    }

    /**
     * Stop the kernel.
     *
     * Stopping the kernel causes all calls to {@see Kernel::executeSync()}
     * or {@see Kernel::adoptSync()} to throw a {@see KernelStoppedException}.
     *
     * The kernel cannot run again until it has stopped completely. That is,
     * the PHP call-stack has unwound to the outer-most call to {@see Kernel::run()},
     * {@see Kernel::executeSync()} or {@see Kernel::adoptSync()}.
     */
    public function stop()
    {
        if ($this->state === self::STATE_RUNNING) {
            $this->state = self::STATE_STOPPING;
            $this->eventLoop->stop();
        }
    }

    /**
     * Schedule a coroutine for execution on a new strand.
     *
     * Execution begins when the kernel is started; or, if called within a
     * strand, when that strand cooperates.
     *
     * @param mixed $coroutine The coroutine to execute.
     */
    public function execute($coroutine) : Strand
    {
        $strand = new ReactStrand($this, $this->api, $this->nextId++, $coroutine);

        $this->eventLoop->futureTick(
            function () use ($strand) {
                $strand->start();
            }
        );

        return $strand;
    }

    /**
     * Execute a coroutine on a new strand and block until it exits.
     *
     * If the kernel is not running, it is run until the strand exits, the
     * kernel is stopped explicitly, or a different strand causes a kernel panic.
     *
     * The kernel's exception handler is bypassed for this strand. Instead, if
     * the strand produces an exception it is re-thrown by this method.
     *
     * This is a convenience method equivalent to:
     *
     *      $strand = $kernel->execute($coroutine);
     *      $kernel->adoptSync($strand);
     *
     * @param mixed $coroutine The coroutine to execute.
     *
     * @return mixed                  The return value of the coroutine.
     * @throws Throwable              The exception produced by the coroutine.
     * @throws TerminatedException    The strand has been terminated.
     * @throws KernelStoppedException The kernel was stopped before the strand exited.
     * @throws KernelPanicException   Some other strand has caused a kernel panic.
     */
    public function executeSync($coroutine)
    {
        $strand = $this->execute($coroutine);

        return $this->adoptSync($strand);
    }

    /**
     * Block until a strand exits.
     *
     * If the kernel is not running, it is run until the strand exits, the
     * kernel is stopped explicitly, or a different strand causes a kernel panic.
     *
     * The kernel's exception handler is bypassed for this strand. Instead, if
     * the strand produces an exception it is re-thrown by this method.
     *
     * @param Strand $strand The strand to wait for.
     *
     * @return mixed                  The return value of the coroutine.
     * @throws Throwable              The exception produced by the coroutine.
     * @throws TerminatedException    The strand has been terminated.
     * @throws KernelStoppedException The kernel was stopped before the strand exited.
     * @throws KernelPanicException   Some other strand has caused a kernel panic.
     */
    public function adoptSync(Strand $strand)
    {
        assert($strand->kernel() === $this, 'kernel can only wait for its own strands');

        if ($this->state === self::STATE_PANIC) {
            throw new KernelPanicException();
        } elseif ($this->state === self::STATE_STOPPING) {
            throw new KernelStoppedException();
        } elseif (!$this->panicExceptions->isEmpty()) {
            throw $this->panicExceptions->dequeue();
        }

        $listener = new AdoptSyncListener();
        $strand->setPrimaryListener($listener);

        if ($listener->isDone) {
            return $listener->get();
        }

        $listener->eventLoop = $this->eventLoop;
        $isFirstLoopRun = $this->state === self::STATE_STOPPED;
        $this->state = self::STATE_RUNNING;

        try {
            try {
                run:
                $this->eventLoop->run();
            } catch (Throwable $e) {
                $this->throw($e);
            }

            if ($this->state === self::STATE_STOPPING) {
                throw new KernelStoppedException();
            } elseif ($this->state === self::STATE_PANIC) {
                if ($isFirstLoopRun) {
                    throw $this->panicExceptions->dequeue();
                } else {
                    throw new KernelPanicException();
                }
            } elseif ($listener->isDone) {
                return $listener->get();
            } else {
                goto run;
            }
        } finally {
            if ($isFirstLoopRun) {
                $this->state = self::STATE_STOPPED;
            }
        }
    }

    /**
     * Set a user-defined exception handler function.
     *
     * The exception handler is invoked when a strand exits with an exception or
     * an internal error occurs in the kernel.
     *
     * The handler will not be called for strands that have a primary listener
     * set, such as those that have been passed to adoptSync() or started by
     * executeSync().
     *
     * The exception handler must accept a single KernelPanicException argument.
     * If the exception was caused by a strand the exception will be the sub-type
     * StrandException. The previous exception is the exception that triggered
     * the call to the exception handler.
     *
     * If the exception handler is unable to handle the exception it can simply
     * re-throw it (or any other exception). This causes the kernel panic and
     * stop running. This is also the behaviour when no exception handler is set.
     *
     * @param callable|null $fn The exception handler (null = remove).
     */
    public function setExceptionHandler(callable $fn = null)
    {
        $this->exceptionHandler = $fn;
    }

    /**
     * Please note that this code is not part of the public API. It may be
     * changed or removed at any time without notice.
     *
     * @access private
     *
     * This constructor is public so that it may be used by auto-wiring
     * dependency injection containers. If you are explicitly constructing an
     * instance please use one of the static factory methods listed below.
     *
     * @see ReactKernel::start()
     * @see ReactKernel::create()
     *
     * @param LoopInterface $eventLoop The event loop.
     * @param Api           $api       The kernel API.
     */
    public function __construct(LoopInterface $eventLoop, Api $api)
    {
        $this->eventLoop = $eventLoop;
        $this->api = $api;
        $this->panicExceptions = new SplQueue();
    }

    /**
     * Send the result of a successful operation.
     *
     * @param mixed       $value  The operation result.
     * @param Strand|null $strand The strand that produced this result upon exit, if any.
     */
    public function send($value = null, Strand $strand = null)
    {
        assert(
            $strand !== null && $strand->kernel() === $this,
            'kernel can only handle notifications from its own strands'
        );
    }

    /**
     * Send the result of an unsuccessful operation.
     *
     * @param Throwable   $exception The operation result.
     * @param Strand|null $strand    The strand that produced this exception upon exit, if any.
     */
    public function throw(Throwable $exception, Strand $strand = null)
    {
        assert(
            $strand === null || $strand->kernel() === $this,
            'kernel can only handle notifications from its own strands'
        );

        // Termination is not an error ...
        if (
            $exception instanceof TerminatedException &&
            $strand === $exception->strand()
        ) {
            return;
        }

        if ($strand === null) {
            $exception = new KernelPanicException('Kernel panic: ' . $exception->getMessage(), $exception);
        } else {
            $exception = new StrandException($strand, $exception);
        }

        if ($this->exceptionHandler) {
            try {
                ($this->exceptionHandler)($exception);

                return;
            } catch (KernelPanicException $e) {
                $exception = $e;
            } catch (Throwable $e) {
                $exception = new KernelPanicException('Kernel panic: ' . $e->getMessage(), $e);
            }
        }

        $this->panicExceptions->enqueue($exception);

        if ($this->state === self::STATE_RUNNING) {
            $this->state = self::STATE_PANIC;
            $this->eventLoop->stop();
        }
    }

    const STATE_STOPPED = 0;
    const STATE_RUNNING = 1;
    const STATE_STOPPING = 2;
    const STATE_PANIC = 3;

    /**
     * @var LoopInterface The event loop.
     */
    private $eventLoop;

    /**
     * @var Api The kernel API.
     */
    private $api;

    /**
     * @var int The next strand ID.
     */
    private $nextId = 1;

    /**
     * @var int The current kernel state.
     */
    private $state = self::STATE_STOPPED;

    /**
     * @var callable|null The exception handler.
     */
    private $exceptionHandler;

    /**
     * @var SplQueue<KernelPanicException> A queue of exceptions that caused the kernel to panic.
     */
    private $panicExceptions;
}
