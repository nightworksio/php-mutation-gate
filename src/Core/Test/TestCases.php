<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_any;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * Which files a project's test suite runs as files of test cases, as its
 * config declares them: a file it names, or one with the suffix of a test
 * directory it is in, and none it leaves out. A helper or a fixture under a
 * test directory is none, though it declares tests of its own.
 */
final readonly class TestCases
{
    /** @param list<SuiteDirectory> $directories */
    private function __construct(private array $directories, private Paths $files, private Paths $excluded)
    {
    }

    /** No file known as a file of test cases: where nothing says how the suite tells them. */
    public static function unknown(): self
    {
        return new self([], Paths::none(), Paths::none());
    }

    /** The files of test cases of these files and these directories, less those left out. */
    public static function of(Paths $files, Paths $excluded, SuiteDirectory ...$directories): self
    {
        return new self(array_values($directories), $files, $excluded);
    }

    /** Whether a file is one of them. */
    public function holds(Path $file): bool
    {
        $named = $this->files->has($file) || array_any(
            $this->directories,
            static fn(SuiteDirectory $directory): bool => $directory->holdsTestCase($file),
        );

        return $named && ! array_any([...$this->excluded], static fn(Path $excluded): bool => $file->within($excluded));
    }

    /** Those of these files that are of them, in their order. */
    public function among(Paths $files): Paths
    {
        $cases = Paths::none();

        foreach ($files as $file) {
            $cases = $this->holds($file) ? $cases->with($file) : $cases;
        }

        return $cases;
    }
}
