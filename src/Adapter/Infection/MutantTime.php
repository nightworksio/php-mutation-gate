<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function getenv;
use function is_numeric;
use function is_string;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The seconds a patched Infection allows one mutant, read in Infection's
 * process (see Patch): the standard mutant limit (ADR-0008, decision 2) of
 * its covering tests' own time, as Infection timed them, between the floor
 * the gate names and Infection's `timeout`, which the gate sets to
 * `timeouts.most`. Where the gate names no floor, as when Infection runs
 * outside it, Infection's own limit.
 */
final class MutantTime
{
    /** The seconds a mutant whose covering tests take this long is allowed, under Infection's `timeout`. */
    public static function of(float $tests, float $timeout): float
    {
        $floor = self::floor();
        $taking = Seconds::of($tests);
        $most = Seconds::of($timeout);

        return $floor instanceof Seconds
            ? MutantLimit::standard()->of($taking, LimitBounds::between($floor, $most))->seconds()
            : MutantLimit::infections()->of($taking, LimitBounds::upTo($most))->seconds();
    }

    /** Whether the gate names its bounds, so no mutant is skipped for the time its tests take. */
    public static function bounded(): bool
    {
        return self::floor() instanceof Seconds;
    }

    /** The floor the gate names, a positive number of seconds; or none. */
    private static function floor(): Seconds|NotGiven
    {
        $floor = getenv(ChildVariable::MutantFloor->value);

        return is_string($floor) && is_numeric($floor) && (float) $floor > 0.0
            ? Seconds::of((float) $floor)
            : NotGiven::value();
    }
}
