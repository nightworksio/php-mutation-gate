<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function is_numeric;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A number of seconds the gate tells a runner's process in a variable, as
 * that process reads it back: none where it was told nothing, no number, or
 * no time past nothing.
 */
final readonly class ToldSeconds
{
    public static function read(string|false $told): Seconds|NotGiven
    {
        if (! is_numeric($told)) {
            return NotGiven::value();
        }

        $seconds = Seconds::of((float) $told);

        return $seconds->seconds() > 0.0 ? $seconds : NotGiven::value();
    }
}
