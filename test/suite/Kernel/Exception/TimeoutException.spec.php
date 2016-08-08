<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel\Exception;

describe(TimeoutException::class, function () {

    it('implements the public api interface', function () {
        expect(
            is_subclass_of(
                TimeoutException::class,
                \Recoil\Exception\TimeoutException::class
            )
        )->to->be->true;
    });

    it('produces a useful message', function () {
        $exception = new TimeoutException(1.25);

        expect($exception->getMessage())->to->equal('The operation timed out after 1.25 second(s).');
    });

});
