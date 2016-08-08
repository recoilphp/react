<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel\Exception;

use Exception;
use Throwable;

/**
 * A kernel panic has occurred by an exception inside the kernel.
 */
class KernelException extends Exception implements
    \Recoil\Exception\KernelPanicException
{
    public function __construct(Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'Unhandled exception in kernel: %s (%s).',
                get_class($previous),
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }
}
