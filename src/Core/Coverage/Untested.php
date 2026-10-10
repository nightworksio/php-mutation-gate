<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function count;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Lines;

/**
 * The lines of a change that no test runs: those a coverage map holds as
 * executable lines the run measuring it missed. A line the map does not hold,
 * such as a comment, is no such line.
 */
final readonly class Untested
{
    /** The lines of each file these changes name that the map says no test runs; none of a file with none. */
    public static function of(Changes $changed, CoverageMap $map): Changes
    {
        $untested = Changes::none();

        foreach ($changed as $change) {
            $missed = $map->lineSets($change->path())->missed();
            $lines = Lines::none();

            foreach ($change->lines() as $line) {
                $lines = $missed->has($line) ? $lines->with($line) : $lines;
            }

            $untested = count($lines) === 0 ? $untested : $untested->with(Change::modified($change->path(), $lines));
        }

        return $untested;
    }
}
