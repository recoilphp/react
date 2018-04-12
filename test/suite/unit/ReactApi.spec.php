<?php

declare(strict_types=1); // @codeCoverageIgnore

namespace Recoil\React;

use Eloquent\Phony\Phony;
use Hamcrest\Core\IsInstanceOf;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Recoil\Kernel\SystemKernel;
use Recoil\Kernel\SystemStrand;
use Recoil\Strand;

describe(ReactApi::class, function () {
    beforeEach(function () {
        $this->eventLoop = Phony::mock(LoopInterface::class);
        $this->timer = Phony::mock(TimerInterface::class);
        $this->eventLoop->addTimer->returns($this->timer->get());

        $this->kernel = Phony::mock(SystemKernel::class);

        $this->strand = Phony::mock(SystemStrand::class);
        $this->strand->kernel->returns($this->kernel);

        $this->substrand = Phony::mock(SystemStrand::class);
        $this->kernel->execute->returns($this->substrand);

        $this->subject = new ReactApi($this->eventLoop->get());
    });

    describe('->cooperate()', function () {
        it('resumes the strand on a future tick', function () {
            $this->subject->cooperate(
                $this->strand->get()
            );

            $fn = $this->eventLoop->futureTick->calledWith('~')->firstCall()->argument();
            expect($fn)->to->satisfy('is_callable');

            $this->strand->noInteraction();

            $fn();

            $this->strand->send->calledWith();
        });
    });

    describe('->sleep()', function () {
        it('resumes the strand with a timer', function () {
            $this->subject->sleep(
                $this->strand->get(),
                10.5
            );

            $fn = $this->eventLoop->addTimer->calledWith(10.5, '~')->firstCall()->argument(1);
            expect($fn)->to->satisfy('is_callable');

            $this->strand->send->never()->called();

            $fn();

            $this->strand->send->calledWith();
        });

        it('cancels the timer if the strand is terminated', function () {
            $this->subject->sleep(
                $this->strand->get(),
                10.5
            );

            $cancel = $this->strand->setTerminator->called()->firstCall()->argument();
            expect($cancel)->to->satisfy('is_callable');

            $this->eventLoop->cancelTimer->never()->called();
            $cancel();
            $this->eventLoop->cancelTimer->called();
        });

        it('uses future tick instead of a timer when passed zero seconds', function () {
            $this->subject->sleep(
                $this->strand->get(),
                0
            );

            $fn = $this->eventLoop->futureTick->calledWith('~')->firstCall()->argument();
            expect($fn)->to->satisfy('is_callable');

            $this->strand->noInteraction();

            $fn();

            $this->strand->send->calledWith();
            $this->eventLoop->addTimer->never()->called();
        });

        it('uses future tick instead of a timer when passed negative seconds', function () {
            $this->subject->sleep(
                $this->strand->get(),
                -1
            );

            $fn = $this->eventLoop->futureTick->calledWith('~')->firstCall()->argument();
            expect($fn)->to->satisfy('is_callable');

            $this->strand->noInteraction();

            $fn();

            $this->strand->send->calledWith();
            $this->eventLoop->addTimer->never()->called();
        });
    });

    describe('->timeout()', function () {
        it('attaches a StrandTimeout instance to the substrand', function () {
            $this->subject->timeout(
                $this->strand->get(),
                10.5,
                '<coroutine>'
            );

            $this->kernel->execute->calledWith('<coroutine>');

            $this->substrand->setPrimaryListener->calledWith(
                IsInstanceOf::anInstanceOf(StrandTimeout::class)
            );
        });
    });

    describe('->eventLoop()', function () {
        it('resumes the strand with the internal event loop', function () {
            $this->subject->eventLoop(
                $this->strand->get()
            );

            $this->strand->send->calledWith($this->eventLoop);
        });
    });

    describe('->write()', function () {
        context('when the stream is a pipe', function () {
            beforeEach(function () {
                $this->pipe = tempnam(sys_get_temp_dir(), 'recoil-test-pipe');
                unlink($this->pipe);
                posix_mkfifo($this->pipe, 0600);
            });

            afterEach(function () {
                unlink($this->pipe);
            });

            // FIXME: This test is currently disabled when running under phpdbg
            // (and hence when building coverage).
            //
            // The call to fwrite() in ReactApi::write() causes the interpreter
            // to crash under phpdbg when the remote end of the pipe is closed.
            (PHP_SAPI === 'phpdbg' ? 'xit' : 'it')('throws an exception if the remote end is closed', function () {
                $read = fopen($this->pipe, 'r+'); // '+' so it won't wait for a reader

                $write = fopen($this->pipe, 'w');
                stream_set_blocking($write, false);

                fclose($read);

                $e = null;

                try {
                    yield 'x' => $write;
                } catch (\RuntimeException $e) {
                }

                expect($e)->not->to->be->null;
            });
        });
    });
});
