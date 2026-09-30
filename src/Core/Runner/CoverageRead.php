<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Path;

/** What a runner is asked to read for a coverage map: the gate's own map another job left in a directory. */
final readonly class CoverageRead
{
    private function __construct(private Path $directory)
    {
    }

    public static function from(Path $directory): self
    {
        return new self($directory);
    }

    /** Where the map is read from. */
    public function directory(): Path
    {
        return $this->directory;
    }
}
