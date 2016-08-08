<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel\Exception;

use Exception;
use Throwable;

/**
 * A container for multiple exceptions produced by API operations that run
 * multiple strands in parallel.
 */
class CompositeException extends Exception implements
    \Recoil\Exception\CompositeException
{
    /**
     * @param array<integer, Throwable> The exceptions.
     */
    public function __construct(array $exceptions)
    {
        $this->exceptions = $exceptions;

        parent::__construct('Multiple exceptions occurred.');
    }

    /**
     * Get the exceptions.
     *
     * The array order matches the order of strand completion. The array keys
     * indicate the order in which the strand was passed to the operation. This
     * allows unpacking of the result with list() to get the results in
     * pass-order.
     *
     * @return array<int, Throwable> The exceptions.
     */
    public function exceptions() : array
    {
        return $this->exceptions;
    }

    /**
     * @var array<integer, Throwable>
     */
    private $exceptions;
}
