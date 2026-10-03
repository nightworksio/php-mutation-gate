<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function count;

use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * How long tests take on their own, one after another, as a coverage map
 * timed them: what a mutant's limit and timeout triage weigh its run
 * against (ADR-0008, decision 2).
 */
final readonly class OwnTime
{
    /** The tests' own time; unmeasured where the map did not time one of them, or where there are none. */
    public static function of(CoverageMap $map, TestIds $tests): Seconds|Unmeasured
    {
        $total = 0.0;

        foreach ($tests as $test) {
            $duration = $map->durationOf($test);

            if (! $duration instanceof Seconds) {
                return $duration;
            }

            $total += $duration->seconds();
        }

        return count($tests) > 0 ? Seconds::of($total) : Unmeasured::duration();
    }
}
