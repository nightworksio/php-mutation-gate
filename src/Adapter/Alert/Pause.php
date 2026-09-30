<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Alert;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usleep;

/** Waiting in real time, as an alert does before it tries again. */
final readonly class Pause
{
    public static function for(int $seconds): void
    {
        usleep(Seconds::of($seconds)->microseconds());
    }
}
