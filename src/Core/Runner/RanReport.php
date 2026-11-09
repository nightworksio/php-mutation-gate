<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use Closure;
use NightWorksIO\MutationGate\Core\Coverage\RecordedBeside;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * php-code-coverage's PHP report a run of the suite under coverage left in
 * this job (ADR-0006), as the runner that read it reads it: the report's
 * file, the serialization format the runner's php-code-coverage reads, what
 * the run recorded beside it, and the path in the project of each file the
 * report names. An engine reads it as data (ADR-0119).
 *
 * @internal
 */
final readonly class RanReport
{
    /** @param Closure(string): Path $relative the path in the project of a file the report names */
    private function __construct(
        private string $file,
        private int $format,
        private RecordedBeside $recorded,
        private Closure $relative,
    ) {
    }

    /** @param Closure(string): Path $relative the path in the project of a file the report names */
    public static function at(string $file, int $format, RecordedBeside $recorded, Closure $relative): self
    {
        return new self($file, $format, $recorded, $relative);
    }

    /** The report's file on disk. */
    public function file(): string
    {
        return $this->file;
    }

    /** The serialization format the runner's php-code-coverage reads. */
    public function format(): int
    {
        return $this->format;
    }

    public function recorded(): RecordedBeside
    {
        return $this->recorded;
    }

    /** The path in the project of a file, as the report names it on disk. */
    public function relative(string $file): Path
    {
        return ($this->relative)($file);
    }
}
