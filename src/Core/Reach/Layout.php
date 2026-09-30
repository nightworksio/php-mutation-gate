<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_any;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

/**
 * Where a repository keeps what the reach rules ask about: the files that
 * decide how the gate runs, the CI definitions that run it, the directories
 * of tests, and the modules.
 */
final readonly class Layout
{
    /**
     * @param Globs                $decisive   the files that decide how the gate runs, spelt from a package's directory
     * @param Globs                $repository the files that decide how the gate runs, spelt from the repository's
     * @param list<SuiteDirectory> $tests      where each package keeps its tests, spelt from its directory
     */
    private function __construct(
        private Globs $decisive,
        private Globs $repository,
        private Globs $definitions,
        private array $tests,
        private Paths $modules,
    ) {
    }

    /**
     * The files that decide how the gate runs in every package, and its tests
     * in `tests`, named as PHPUnit names them by default. Those files are the
     * gate's config, the package's `composer.json`, its PHPUnit config, and
     * the files that define the runner, each spelt from the package's own
     * directory.
     */
    public static function standard(Paths $runnerDefinitions): self
    {
        $decisive = [
            ...array_map(Path::of(...), Format::fileNames()),
            Manifest::fileIn(Path::root()),
            ...PhpUnitConfig::candidatesIn(Path::root()),
            ...$runnerDefinitions,
        ];

        return new self(
            Globs::of(...array_map(static fn(Path $file): Glob => Glob::of($file->value()), $decisive)),
            Globs::of(),
            Globs::of(),
            [SuiteDirectory::conventional()],
            Paths::none(),
        );
    }

    /**
     * This layout, where more files decide how the gate runs, spelt from the
     * repository's directory as `reach.everything` spells them, a preset's
     * paths among them.
     */
    public function decidedAlsoBy(Glob $files): self
    {
        return new self(
            $this->decisive,
            $this->repository->with($files),
            $this->definitions,
            $this->tests,
            $this->modules,
        );
    }

    /** This layout, where a CI definition runs the gate. */
    public function runBy(Glob $definition): self
    {
        return new self(
            $this->decisive,
            $this->repository,
            $this->definitions->with($definition),
            $this->tests,
            $this->modules,
        );
    }

    /** This layout, with each package's tests in these directories of it, as its PHPUnit config names them. */
    public function testedIn(SuiteDirectory ...$directories): self
    {
        return new self(
            $this->decisive,
            $this->repository,
            $this->definitions,
            array_values($directories),
            $this->modules,
        );
    }

    /** This layout, with a module: a directory with a `composer.json` of its own inside a package. */
    public function withModule(Path $module): self
    {
        return new self(
            $this->decisive,
            $this->repository,
            $this->definitions,
            $this->tests,
            $this->modules->with($module),
        );
    }

    /** Whether a file decides how the gate runs in the package at a directory. */
    public function decides(Path $file, Path $package): bool
    {
        return $this->repository->matches($file) || $this->decisive->matches($file->relativeTo($package));
    }

    /** Whether a file is a CI definition that runs the gate. */
    public function runsTheGate(Path $file): bool
    {
        return $this->definitions->matches($file);
    }

    /**
     * Whether a file, spelt from its package's directory, is a file of test
     * cases: its name ends in the suffix of a directory of tests it is in.
     */
    public function isTest(Path $inPackage): bool
    {
        return array_any($this->tests, static fn(SuiteDirectory $tests): bool => $tests->holdsTestCase($inPackage));
    }

    /** Whether a file, spelt from its package's directory, is test support: PHP under the tests, not of test cases. */
    public function isSupport(Path $inPackage): bool
    {
        return array_any($this->tests, static fn(SuiteDirectory $tests): bool => $tests->holds($inPackage))
            && $inPackage->isPhp()
            && ! $this->isTest($inPackage);
    }

    /** The modules a file is inside. */
    public function modulesHolding(Path $file): Paths
    {
        $modules = [];

        foreach ($this->modules as $module) {
            if ($file->within($module)) {
                $modules[] = $module;
            }
        }

        return Paths::of(...$modules);
    }
}
