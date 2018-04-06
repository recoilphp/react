<?php

// The TimerInterface was moved to the root React\EventLoop namespace in
// react/event-loop 0.5.
//
// Recoil tests uses the newer location, ensuring compatibility going forward.
//
// Backwards compatibility is maintained by creating an alias at the new
// location to the old location.
if (
    !interface_exists('React\EventLoop\TimerInterface') &&
    interface_exists('React\EventLoop\Timer\TimerInterface')
) {
    class_alias(
        'React\EventLoop\Timer\TimerInterface', // react/event-loop <  0.5
        'React\EventLoop\TimerInterface'        // react/event-loop >= 0.5
    );
}
