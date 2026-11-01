<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_any;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Test\TestCases;

/**
 * The test suite a project's test framework config declares (see
 * SuiteConfig): its test directories, each with the suffix that tells its
 * files of test cases, the files it names, those it leaves out, its
 * suites by their names, and those of its suites the gate runs none of the
 * tests of, as they serve the application from another process. Without a
 * config, or one whose directories are none, the tests are in the
 * framework's conventional directory.
 *
 * @internal
 */
final readonly class TestSuite
{
    /**
     * @param non-empty-list<SuiteDirectory> $directories
     * @param SuiteDirectory                 $conventional where the framework keeps tests when nothing says otherwise
     * @param list<string>                   $elsewhere    the suites that serve the application from another process
     */
    private function __construct(
        private array $directories,
        private SuiteDirectory $conventional,
        private Paths $files,
        private Paths $excluded,
        private DeclaredSuites $suites,
        private array $elsewhere = [],
    ) {
    }

    /** The tests in the framework's conventional directory, and nothing left out, in no suite of a name. */
    public static function conventional(SuiteDirectory $directory): self
    {
        return new self([$directory], $directory, Paths::none(), Paths::none(), DeclaredSuites::none());
    }

    /**
     * The tests in these directories and files, less those left out, in
     * these suites; in the conventional directory where it names none.
     *
     * @param list<SuiteDirectory> $directories
     */
    public static function of(
        array $directories,
        SuiteDirectory $conventional,
        Paths $files,
        Paths $excluded,
        DeclaredSuites $suites,
    ): self {
        return new self(
            $directories === [] ? [$conventional] : $directories,
            $conventional,
            $files,
            $excluded,
            $suites,
        );
    }

    /**
     * These tests, leaving out every test of these suites, which serve the application from another process.
     *
     * @param list<string> $suites
     */
    public function leaving(array $suites): self
    {
        return new self(
            $this->directories,
            $this->conventional,
            $this->files,
            $this->excluded,
            $this->suites,
            [...$this->elsewhere, ...$suites],
        );
    }

    /**
     * The suites whose tests the gate runs none of, as they serve the application from another process.
     *
     * @return list<string>
     */
    public function elsewhere(): array
    {
        return $this->elsewhere;
    }

    /** Where the framework keeps tests when nothing it configures says otherwise, as every other package does. */
    public function conventionalDirectory(): SuiteDirectory
    {
        return $this->conventional;
    }

    /** Whether a file is one of the suite's: in a test directory or named, and not left out. */
    public function holds(Path $file): bool
    {
        return ($this->files->has($file) || array_any(
            $this->directories,
            static fn(SuiteDirectory $directory): bool => $directory->holds($file),
        )) && ! $this->excludes($file);
    }

    /** Whether a file is one of the suite's files of test cases: named, or with its directory's suffix. */
    public function holdsTestCase(Path $file): bool
    {
        return $this->testCases()->holds($file);
    }

    /** The suite's files of test cases, as a runner is told them. */
    public function testCases(): TestCases
    {
        return TestCases::of($this->files, $this->excluded, ...$this->directories);
    }

    /**
     * The directories the tests are in, each with the suffix of its files of test cases.
     *
     * @return non-empty-list<SuiteDirectory>
     */
    public function directories(): array
    {
        return $this->directories;
    }

    /** Where the tests are: each directory once, in the order the config names them. */
    public function paths(): Paths
    {
        $paths = Paths::none();

        foreach ($this->directories as $directory) {
            $paths = $paths->with($directory->path());
        }

        return $paths;
    }

    /** Each suite the config declares, by its name, in its order (ADR-0025). */
    public function suites(): DeclaredSuites
    {
        return $this->suites;
    }

    private function excludes(Path $file): bool
    {
        return array_any([...$this->excluded], static fn(Path $excluded): bool => $file->within($excluded));
    }
}
