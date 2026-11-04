<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How many times its covering tests' own time a mutant a test killed took
 * to be killed, as its run timed it and the map its shard was handed timed
 * its tests: what a learned `timeouts.multiple` is read from (ADR-0073).
 *
 * @internal
 */
final readonly class KillPace
{
    /**
     * Each mutant a test killed whose run and tests were both timed, its pace.
     *
     * @return list<Multiple>
     */
    public static function ofAll(Mutants $mutants, CoverageMap $map): array
    {
        $paces = [];

        foreach ($mutants as $mutant) {
            $pace = self::of($mutant, $map);
            $paces = $pace instanceof NotGiven ? $paces : [...$paces, $pace];
        }

        return $paces;
    }

    /** A mutant's pace; none where a test did not kill it, or its run or its tests were not timed. */
    private static function of(Mutant $mutant, CoverageMap $map): Multiple|NotGiven
    {
        $took = $mutant->duration();
        $location = $mutant->location();
        $tests = OwnTime::of($map, $map->testsCovering($location->file(), $location->start(), $location->last()));

        return $mutant->status() === MutantStatus::Killed
            && $took instanceof Seconds
            && $tests instanceof Seconds
            && $tests->seconds() > 0.0
            ? Multiple::of($took->seconds() / $tests->seconds())
            : NotGiven::value();
    }
}
