<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel\Exception;

use RuntimeException;

/**
 * An operation has timed out.
 */
class TimeoutException extends RuntimeException implements
    \Recoil\Exception\TimeoutException
{
    public function __construct(float $seconds)
    {
        parent::__construct(
            'The operation timed out after ' . $seconds . ' second(s).'
        );
    }
}
