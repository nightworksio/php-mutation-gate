<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function count;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Measurement;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HotPaths;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * A hot path nothing holds, under `--measure` (ADR-0005, decision 11;
 * ADR-0017, decision 10): a file most of the suite runs through, found over
 * the map the measured run gave and the units a run finds held. Only a
 * measured run can say what is held, since the groups that hold are the ones
 * the runner lists. The time at stake is what the ledgers learned the file
 * takes, or else what its covering tests take once, for each of its mutants.
 */
final readonly class HotPath
{
    private const string FOUND = '`%s` is run by %d of %d tests, and nothing holds it.';

    private const string WHY = 'Each of its mutants runs most of the suite, which costs time and never a verdict.';

    private const string FIX = 'Hold it with the tests that assert what it does: #[Holds(\'%s\')] on them.';

    public static function in(Observations $observed): Findings
    {
        $measured = $observed->measurement();
        $settings = $observed->settings();

        return $measured instanceof Measurement && $settings instanceof Settings
            ? self::measured($measured, $settings->reach()->hotPaths())
            : Findings::none();
    }

    private static function measured(Measurement $measured, HotPaths $hotPaths): Findings
    {
        $map = $measured->coverage();

        return $map instanceof CoverageMap ? self::hot($map, $measured, $hotPaths) : Findings::none();
    }

    private static function hot(CoverageMap $map, Measurement $measured, HotPaths $hotPaths): Findings
    {
        $findings = Findings::none();

        foreach ($hotPaths->hot($map, $measured->held()) as $file) {
            $findings = $findings->and(Findings::of(Finding::of(
                Slug::HotPathUnheld,
                Severity::Slow,
                sprintf(self::FOUND, $file->value(), count($map->testsCoveringFile($file)), count($map->tests())),
                self::WHY,
                sprintf(self::FIX, $file->value()),
            )->costing(self::atStake($file, $map, $measured))));
        }

        return $findings;
    }

    /** What the ledgers learned the file takes, or else what its covering tests take once. */
    private static function atStake(Path $file, CoverageMap $map, Measurement $measured): Seconds
    {
        $learned = $measured->timings()->secondsFor($file);

        if ($learned instanceof Seconds) {
            return $learned;
        }

        $seconds = 0.0;

        foreach ($map->testsCoveringFile($file) as $test) {
            $duration = $map->durationOf($test);
            $seconds += $duration instanceof Seconds ? $duration->seconds() : 0.0;
        }

        return Seconds::of($seconds);
    }
}
