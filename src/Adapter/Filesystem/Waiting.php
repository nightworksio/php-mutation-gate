<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use Closure;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usleep;

/** Waiting in real time between two looks at the files `watch` polls (ADR-0010, decision 1). */
final readonly class Waiting
{
    /**
     * A wait this long, which then says to go on watching: the command is
     * stopped from outside it, with Ctrl-C.
     *
     * @return Closure(): bool
     */
    public static function every(Seconds $interval): Closure
    {
        return static function () use ($interval): bool {
            usleep($interval->microseconds());

            return true;
        };
    }
}
