<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel\Exception;

use Eloquent\Phony\Phony;
use Throwable;

describe(CompositeException::class, function () {

    it('implements the public api interface', function () {
        expect(
            is_subclass_of(
                CompositeException::class,
                \Recoil\Exception\CompositeException::class
            )
        )->to->be->true;
    });

    it('accepts multiple previous exceptions', function () {
        $exception1 = Phony::mock(Throwable::class)->get();
        $exception2 = Phony::mock(Throwable::class)->get();

        $exceptions = [
            1 => $exception1,
            0 => $exception2,
        ];

        $exception = new CompositeException($exceptions);

        expect($exception->getMessage())->to->equal('Multiple exceptions occurred.');
        expect($exception->exceptions() === $exceptions)->to->be->true;
    });

});
