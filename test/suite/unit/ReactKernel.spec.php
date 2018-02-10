<?php

declare(strict_types=1); // @codeCoverageIgnore

namespace Recoil\React;

use Eloquent\Phony\Phony;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use Recoil\Kernel\Api;
use Throwable;

describe(ReactKernel::class, function () {
    beforeEach(function () {
        $this->eventLoop = Phony::mock(LoopInterface::class);
        $this->api = Phony::mock(Api::class);

        $this->subject = new ReactKernel(
            $this->eventLoop->get(),
            $this->api->get()
        );
    });

    describe('::create()', function () {
        it('can be called without a loop', function() {
            $kernel = ReactKernel::create();
            expect($kernel)->to->be->an->instanceof(ReactKernel::class);
        });
    });

    describe('->execute()', function () {
        it('dispatches the coroutine on a future tick', function () {
            $strand = $this->subject->execute('<coroutine>');
            expect($strand)->to->be->an->instanceof(ReactStrand::class);

            $fn = $this->eventLoop->futureTick->calledWith('~')->firstCall()->argument();
            expect($fn)->to->satisfy('is_callable');

            $this->api->noInteraction();

            $fn();

            $this->api->__dispatch->calledWith(
                $strand,
                0,
                '<coroutine>'
            );
        });
    });

    describe('->stop()', function () {
        it('stops the event loop', function () {
            $this->eventLoop->run->does(function () {
                $this->subject->stop();
            });
            $this->subject->run();
            $this->eventLoop->stop->called();
        });

        it('does nothing if the kernel is stopped', function () {
            $this->subject->stop();
            $this->eventLoop->noInteraction();
        });

        it('causes run() to return', function () {
            $exception = Phony::mock(Throwable::class)->get();
            $this->eventLoop->run->does(function () use ($exception) {
                $this->subject->stop();
            });

            expect(function () {
                $this->subject->run();
            })->to->be->ok;
        });
    });
});
