<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel;

use Recoil\Kernel\Exception\PrimaryListenerRemovedException;

/**
 * A low-level strand interface for use by the kernel.
 */
interface KernelStrand extends Strand
{
    /**
     * Start the strand.
     *
     * @return null
     */
    public function start();

    /**
     * Set the primary listener.
     *
     * If the current primary listener is not the kernel, it is notified with
     * a {@see PrimaryListenerRemovedException}.
     *
     * @return null
     */
    public function setPrimaryListener(Listener $listener);

    /**
     * Set the primary listener to the kernel.
     *
     * The current primary listener is not notified.
     *
     * @return null
     */
    public function clearPrimaryListener();

    /**
     * Set the strand 'terminator'.
     *
     * The terminator is a function invoked when the strand is terminated. It is
     * used by the kernel API to clean up any pending asynchronous operations.
     *
     * The terminator function is removed without being invoked when the strand
     * is resumed.
     *
     * @return null
     */
    public function setTerminator(callable $fn = null);

    /**
     * Create a uni-directional link to another strand.
     *
     * If this strand exits, any linked strands are terminated.
     *
     * @return null
     */
    public function link(Strand $strand);

    /**
     * Break a previously created uni-directional link to another strand.
     *
     * @return null
     */
    public function unlink(Strand $strand);
}
