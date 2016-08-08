<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel\Exception;

use Recoil\Strand;
use RuntimeException;

/**
 * Indicates that a strand has been explicitly terminated.
 */
class TerminatedException extends RuntimeException implements
    \Recoil\Exception\TerminatedException
{
    /**
     * @param Strand $strand The terminated strand.
     */
    public function __construct(Strand $strand)
    {
        $this->strand = $strand;

        parent::__construct('Strand #' . $strand->id() . ' was terminated.');
    }

    /**
     * Get the terminated strand.
     */
    public function strand() : Strand
    {
        return $this->strand;
    }

    /**
     * @var Strand The terminated strand.
     */
    private $strand;
}
