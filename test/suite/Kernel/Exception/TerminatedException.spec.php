<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel\Exception;

use Eloquent\Phony\Phony;
use Recoil\Strand;

describe(TerminatedException::class, function () {

    beforeEach(function () {
        $this->strand = Phony::mock(Strand::class);
        $this->strand->id->returns(123);

        $this->subject = new TerminatedException($this->strand->get());
    });

    it('implements the public api interface', function () {
        expect(
            is_subclass_of(
                TerminatedException::class,
                \Recoil\Exception\TerminatedException::class
            )
        )->to->be->true;
    });

    it('produces a useful message', function () {
        expect($this->subject->getMessage())->to->equal('Strand #123 was terminated.');
    });

    it('exposes the terminated strand', function () {
        expect($this->subject->strand())->to->equal($this->strand->get());
    });

});
