<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_any;
use function array_map;
use function array_values;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
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
        private string|NotGiven $unnamed,
    ) {
    }

    /**
     * The files that decide how the gate runs in every package, and its tests
     * in `tests`, named as PHPUnit names them by default. Those files are the
     * gate's config, the package's `composer.json`, `composer.lock` and
     * `vendor/composer/installed.json`, since a dependency that moves changes
     * what every mutant runs against and no coverage map shows it, its
     * PHPUnit config, and the files that define the runner, each spelt from
     * the package's own directory.
     */
    public static function standard(Paths $runnerDefinitions): self
    {
        $decisive = [
            ...array_map(Path::of(...), Format::fileNames()),
            Manifest::fileIn(Path::root()),
            Manifest::lockIn(Path::root()),
            Installed::fileIn(Path::of(Manifest::VENDOR)),
            ...PhpUnitConfig::candidatesIn(Path::root()),
            ...$runnerDefinitions,
        ];

        return new self(
            Globs::of(...array_map(static fn(Path $file): Glob => Glob::of($file->value()), $decisive)),
            Globs::of(),
            Globs::of(),
            [SuiteDirectory::conventional()],
            Paths::none(),
            NotGiven::value(),
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
            $this->unnamed,
        );
    }

    /**
     * This layout, where the config file reads these files beside itself:
     * each decides how the gate runs as the config does, spelt from the
     * repository's directory; and where it reads one it cannot name, every
     * file does, for that reason (ADR-0005, decision 4).
     */
    public function readByTheConfig(ConfigReads $reads): self
    {
        $layout = $this;

        foreach ($reads->files() as $file) {
            $layout = $layout->decidedAlsoBy(Glob::of($file->value()));
        }

        return new self(
            $layout->decisive,
            $layout->repository,
            $layout->definitions,
            $layout->tests,
            $layout->modules,
            $reads->unnamedBecause(),
        );
    }

    /** Why every change decides how the gate runs, where the config reads a file it cannot name; nothing otherwise. */
    public function unnamed(): string|NotGiven
    {
        return $this->unnamed;
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
            $this->unnamed,
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
            $this->unnamed,
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
            $this->unnamed,
        );
    }

    /** Whether a file decides how the gate runs in the package at a directory. */
    public function decides(Path $file, Path $package): bool
    {
        return is_string($this->unnamed)
            || $this->repository->matches($file)
            || $this->decisive->matches($file->relativeTo($package));
    }

    /** Whether a file is a CI definition that runs the gate. */
    public function runsTheGate(Path $file): bool
    {
        return $this->definitions->matches($file);
    }

    /** The path of a change, as it is or as it was, that is a CI definition that runs the gate, where one is. */
    public function definitionIn(Change $change): Path|NotDeciding
    {
        foreach (Paths::of($change->path(), $change->previousPath()) as $path) {
            if ($this->runsTheGate($path)) {
                return $path;
            }
        }

        return NotDeciding::change();
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
        return $this->isInTests($inPackage) && $inPackage->isPhp() && ! $this->isTest($inPackage);
    }

    /** Whether a file, spelt from its package's directory, is inside a directory of tests, whatever it holds. */
    public function isInTests(Path $inPackage): bool
    {
        return array_any($this->tests, static fn(SuiteDirectory $tests): bool => $tests->holds($inPackage));
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
