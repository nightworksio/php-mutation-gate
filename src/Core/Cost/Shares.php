<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_sum;
use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;
use function str_starts_with;

/**
 * What a finished shard teaches: the time it spent mutating, shared among its
 * units in proportion to their mutants' durations. Mutants run side by side,
 * so their durations add up to a multiple of the time the runner was held,
 * and the share turns them back into runner time. A mutant with no duration
 * of its own, as every mutant of Infection's is, stands in with the time the
 * tests covering its line took. Where nothing was timed at all, the units
 * share the time equally.
 */
final readonly class Shares
{
    public static function of(Units $units, Mutants $mutants, CoverageMap $coverage, Measurement $measured): Timings
    {
        $weights = [];

        foreach ($units as $at => $unit) {
            $weights[$at] = self::weightOf($unit->path(), $mutants, $coverage);
        }

        $total = array_sum($weights);
        $timings = [];

        foreach ($units as $at => $unit) {
            $share = $total > 0.0 ? $weights[$at] / $total : 1 / count($weights);
            $timings[] = Timing::of(
                $unit->path(),
                Seconds::of($measured->spent()->seconds() * $share),
                $measured->runner(),
                $measured->at(),
            );
        }

        return Timings::of(...$timings);
    }

    private static function weightOf(Path $unit, Mutants $mutants, CoverageMap $coverage): float
    {
        $weight = 0.0;

        foreach ($mutants as $mutant) {
            $weight += self::isOf($mutant, $unit) ? self::durationOf($mutant, $coverage) : 0.0;
        }

        return $weight;
    }

    /** Whether a mutant is in a unit: in its file, or in a file under the path a held unit names. */
    private static function isOf(Mutant $mutant, Path $unit): bool
    {
        $file = $mutant->location()->file();

        return $file->equals($unit) || str_starts_with($file->value(), sprintf('%s/', $unit->value()));
    }

    private static function durationOf(Mutant $mutant, CoverageMap $coverage): float
    {
        $duration = $mutant->duration();

        if ($duration instanceof Seconds) {
            return $duration->seconds();
        }

        $standIn = 0.0;

        foreach ($coverage->testsCovering($mutant->location()->file(), $mutant->location()->start()) as $test) {
            $took = $coverage->durationOf($test);
            $standIn += $took instanceof Seconds ? $took->seconds() : 0.0;
        }

        return $standIn;
    }
}
