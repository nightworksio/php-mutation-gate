<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * A run of the suite under coverage that the project started itself, in
 * this job, which left the runner's own reports in a directory under the
 * names the runner's coverage run gives them. A runner's report can be code
 * that reading it runs, so it is read only in the job that wrote it
 * (ADR-0006, decision 1).
 */
final readonly class CoverageRan
{
    private function __construct(private Path $directory)
    {
    }

    public static function in(Path $directory): self
    {
        return new self($directory);
    }

    /** Where the runner's reports are. */
    public function directory(): Path
    {
        return $this->directory;
    }
}
