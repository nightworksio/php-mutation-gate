<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;

use function sprintf;

/**
 * What `baseline` shows: each tree's and each package's security set's
 * committed floor beside its last measured score, or that a unit of it has
 * no result yet (ADR-0003 decision 6, ADR-0021 decision 17).
 */
final readonly class MeasuredFloors
{
    private const string UNMEASURED = <<<'SAID'
        %s: floor %s, not measured: a unit of it has no result yet. Run mutation-gate to measure it.
        SAID;

    /** A floor and the score last measured against it: what it is of, the floor, and the score. */
    private const string SHOWN = '%s: floor %s, measured %s';

    /** @return list<string> each tree's and security set's floor and its last measured score */
    public static function of(Baseline $committed, Measured $measured): array
    {
        $lines = [];

        foreach ($measured->trees() as $tree) {
            $path = $tree->tree()->path();
            $lines[] = self::line($path->value(), $committed->entryOf($path), $tree->score());
        }

        foreach ($measured->unmeasured() as $tree) {
            $lines[] = sprintf(self::UNMEASURED, $tree->value(), self::floorOf($committed->entryOf($tree)));
        }

        foreach ($measured->security() as $set) {
            $package = $set->package()->path();
            $named = sprintf(SecurityVerdict::SET, $package->value());
            $lines[] = self::line($named, $committed->securityOf($package), $set->score());
        }

        foreach ($measured->unmeasuredSecurity() as $package) {
            $named = sprintf(SecurityVerdict::SET, $package->value());
            $lines[] = sprintf(self::UNMEASURED, $named, self::floorOf($committed->securityOf($package)));
        }

        return $lines;
    }

    private static function line(string $named, Entry|Unrecorded $entry, Score|NothingToMutate $score): string
    {
        return sprintf(
            self::SHOWN,
            $named,
            self::floorOf($entry),
            $score instanceof Score
                ? BaselineFile::number(Floor::ofHundredths($score->hundredths()))
                : 'nothing to mutate',
        );
    }

    private static function floorOf(Entry|Unrecorded $entry): string
    {
        return $entry instanceof Entry ? BaselineFile::number($entry->floor()) : 'none';
    }
}
