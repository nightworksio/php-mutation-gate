<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_any;
use function array_map;

use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function str_ends_with;

/**
 * Where a repository keeps what the reach rules ask about: the files that
 * decide how the gate runs, the CI definitions that run it, the directories
 * of tests, and the modules.
 */
final readonly class Layout
{
    /** The files besides its config that decide how the gate runs in every package, spelt from its own directory. */
    private const array DECISIVE = [
        'composer.json',
        'phpunit.xml',
        'phpunit.xml.dist',
        'phpunit.dist.xml',
        'tests/Pest.php',
        'infection.json5',
        'infection.json',
        'infection.json5.dist',
        'infection.json.dist',
    ];

    /** Where a package keeps its tests, unless its PHPUnit config says otherwise. */
    private const string TESTS = 'tests';

    /** How the name of a file of test cases ends. */
    private const string TEST_CASES = 'Test.php';

    /** How the name of a PHP file ends. */
    private const string PHP = '.php';

    private function __construct(
        private Globs $decisive,
        private Globs $definitions,
        private Paths $tests,
        private Paths $modules,
    ) {
    }

    /** The files every project decides with, and its tests in `tests`. */
    public static function standard(): self
    {
        return new self(
            Globs::of(...array_map(Glob::of(...), [...Format::fileNames(), ...self::DECISIVE])),
            Globs::of(),
            Paths::of(Path::of(self::TESTS)),
            Paths::none(),
        );
    }

    /** This layout, where more files decide how the gate runs: a preset's paths, `reach.everything`, a bootstrap. */
    public function decidedAlsoBy(Glob $files): self
    {
        return new self($this->decisive->with($files), $this->definitions, $this->tests, $this->modules);
    }

    /** This layout, where a CI definition runs the gate. */
    public function runBy(Glob $definition): self
    {
        return new self($this->decisive, $this->definitions->with($definition), $this->tests, $this->modules);
    }

    /** This layout, with each package's tests in these directories of it. */
    public function testedIn(Paths $directories): self
    {
        return new self($this->decisive, $this->definitions, $directories, $this->modules);
    }

    /** This layout, with a module: a directory with a `composer.json` of its own inside a package. */
    public function withModule(Path $module): self
    {
        return new self($this->decisive, $this->definitions, $this->tests, $this->modules->with($module));
    }

    /** Whether a file, spelt from its package's directory, decides how the gate runs. */
    public function decides(Path $inPackage): bool
    {
        return $this->decisive->matches($inPackage);
    }

    /** Whether a file is a CI definition that runs the gate. */
    public function runsTheGate(Path $file): bool
    {
        return $this->definitions->matches($file);
    }

    /** Whether a file, spelt from its package's directory, is a file of test cases. */
    public function isTest(Path $inPackage): bool
    {
        return $this->isUnderTests($inPackage) && str_ends_with($inPackage->value(), self::TEST_CASES);
    }

    /** Whether a file, spelt from its package's directory, is test support: PHP under the tests, not of test cases. */
    public function isSupport(Path $inPackage): bool
    {
        return $this->isUnderTests($inPackage)
            && str_ends_with($inPackage->value(), self::PHP)
            && ! str_ends_with($inPackage->value(), self::TEST_CASES);
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

    private function isUnderTests(Path $inPackage): bool
    {
        return array_any([...$this->tests], static fn(Path $tests): bool => $inPackage->within($tests));
    }
}
