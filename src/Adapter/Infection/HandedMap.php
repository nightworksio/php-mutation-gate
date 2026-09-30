<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * The coverage map another job handed over in a directory: only ever the
 * gate's own, which is data, and never the reports the runner wrote there.
 */
final readonly class HandedMap
{
    public static function in(Project $project, Path $directory): CoverageMap|CannotJudge
    {
        $file = $project->absolute(CoverageMapFile::in($directory));

        return is_file($file)
            ? CoverageMapFile::decode(sprintf('%s', file_get_contents($file)))
            : CoverageMapFile::missingAt($file);
    }
}
