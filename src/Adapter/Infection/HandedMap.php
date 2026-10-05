<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;
use function ini_get;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\HandoffLimits;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The coverage map another job handed over in a directory: only ever the
 * gate's own, which is data, and never the reports the runner wrote there.
 */
final readonly class HandedMap
{
    public static function in(Project $project, Path $directory): CoverageMap|CannotJudge
    {
        $file = $project->absolute(CoverageMapFile::in($directory));
        $limits = HandoffLimits::under(ini_get('memory_limit'));
        $bytes = is_file($file) ? file_get_contents($file, length: $limits->readable()) : false;

        return is_string($bytes) ? CoverageMapFile::decode($bytes, $limits) : CoverageMapFile::missingAt($file);
    }
}
