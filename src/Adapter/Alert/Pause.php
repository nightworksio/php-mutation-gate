<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Alert;

use function usleep;

/** Waiting in real time, as an alert does before it tries again. */
final readonly class Pause
{
    private const int MICROSECONDS = 1_000_000;

    public static function for(int $seconds): void
    {
        usleep($seconds * self::MICROSECONDS);
    }
}
