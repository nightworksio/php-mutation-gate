<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;

/** A report to write (ADR-0009): the reporter a config chooses, and where a file report goes. */
final readonly class Report
{
    private function __construct(private Choice $reporter, private Path|Absent $path)
    {
    }

    public static function of(Choice $reporter, Path|Absent $path): self
    {
        return new self($reporter, $path);
    }

    public function reporter(): Choice
    {
        return $this->reporter;
    }

    /** Where the report is written; a reporter that writes no file has none. */
    public function path(): Path|Absent
    {
        return $this->path;
    }
}
