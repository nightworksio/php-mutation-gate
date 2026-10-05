<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;

/**
 * Where a coverage map a job holds was measured (ADR-0020, decision 3): at
 * the checkout as it is now, for a map this job measured, or where the map
 * another job wrote says, for a map read from a directory.
 */
final readonly class Measuring
{
    public static function of(Adapters $adapters, CoverageRun|CoverageRead $coverage): MeasuredAt|Unplaced
    {
        return $coverage instanceof CoverageRun ? self::now($adapters) : self::recorded($adapters, $coverage);
    }

    /** At the checkout's commit, as clean as git says it is now. */
    public static function now(Adapters $adapters): MeasuredAt|Unplaced
    {
        return MeasuredAt::now($adapters->repository->head(), $adapters->repository->isClean());
    }

    private static function recorded(Adapters $adapters, CoverageRead $coverage): MeasuredAt|Unplaced
    {
        $limits = Handoff::limits();
        $contents = $adapters->project->readAtMost(CoverageMapFile::in($coverage->directory()), $limits->packed());

        return $contents instanceof Contents ? MeasuredAt::recordedIn($contents->text(), $limits) : Unplaced::map();
    }
}
