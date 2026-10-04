<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_any;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * One `<testsuite>` of the project's PHPUnit config, by its name: its
 * directories and files, less each `<exclude>`. Pest runs on the same config,
 * so its suites are these too (ADR-0025, decision 8).
 */
final readonly class DeclaredSuite
{
    /** @param list<SuiteDirectory> $directories */
    private function __construct(
        private string $name,
        private array $directories,
        private Paths $files,
        private Paths $excluded,
    ) {
    }

    /** A suite of this name, over these directories and files, less what it excludes. */
    public static function named(string $name, Paths $files, Paths $excluded, SuiteDirectory ...$directories): self
    {
        return new self($name, array_values($directories), $files, $excluded);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Whether a test's file is one of the suite's: in one of its directories or named, and not left out. */
    public function holds(Path $file): bool
    {
        $in = $this->files->has($file)
            || array_any($this->directories, static fn(SuiteDirectory $directory): bool => $directory->holds($file));

        return $in && ! array_any([...$this->excluded], static fn(Path $excluded): bool => $file->within($excluded));
    }
}
