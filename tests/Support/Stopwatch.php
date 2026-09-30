<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function hrtime;

/**
 * How long a piece of work takes, for the tests that keep a hot path linear.
 * Each such test sizes its work to take a small part of {@see BOUND} in
 * linear time, so that work quadratic in its size takes many times the bound.
 */
final class Stopwatch
{
    /** The seconds a piece of work at scale may take, generous enough for a slow runner measuring coverage. */
    public const float BOUND = 2.0;

    private const float NANOSECONDS = 1e9;

    /** The seconds a piece of work took. */
    public static function seconds(callable $work): float
    {
        $started = hrtime(as_number: true);
        $work();

        return (hrtime(as_number: true) - $started) / self::NANOSECONDS;
    }
}
