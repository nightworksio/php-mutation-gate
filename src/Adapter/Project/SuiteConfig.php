<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\CodeceptionConfig;
use NightWorksIO\MutationGate\Core\Runner\PhpSpecConfig;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\TestoConfig;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

/**
 * The config a project's tests are declared in: PhpSpec's, for the PhpSpec
 * runner, Codeception's, for the Codeception runner, Testo's, for the Testo
 * runner, and PHPUnit's, for every other runner, which all run PHPUnit's
 * tests.
 */
enum SuiteConfig
{
    case PhpUnit;
    case PhpSpec;
    case Codeception;
    case Testo;

    /** The config the runner of this name reads its tests from. */
    public static function ofRunner(string $runner): self
    {
        return match ($runner) {
            BuiltinRunner::PhpSpec->value => self::PhpSpec,
            BuiltinRunner::Codeception->value => self::Codeception,
            BuiltinRunner::Testo->value => self::Testo,
            default => self::PhpUnit,
        };
    }

    /** Where the config would be in a directory, in the order its framework looks for it. */
    public function candidatesIn(Path $directory): Paths
    {
        return match ($this) {
            self::PhpUnit => PhpUnitConfig::candidatesIn($directory),
            self::PhpSpec => PhpSpecConfig::candidatesIn($directory),
            self::Codeception => CodeceptionConfig::candidatesIn($directory),
            self::Testo => TestoConfig::candidatesIn($directory),
        };
    }

    /** The suite a config, read from this file in a project's root, declares, its wildcards expanded from the root. */
    public function declaredIn(Contents $config, Path $file, Root $root): TestSuite|CannotJudge
    {
        return match ($this) {
            self::PhpUnit => PhpUnitSuite::declaredIn($config, $file, DirectoryGlob::from($root)),
            self::PhpSpec => PhpSpecSuite::declaredIn($config, $file),
            self::Codeception => CodeceptionSuite::declaredIn($config, $file, $root),
            self::Testo => TestoSuite::declaredIn($config, $file),
        };
    }

    /** The suite where the project has no config. */
    public function conventional(): TestSuite
    {
        return match ($this) {
            self::PhpUnit => TestSuite::conventional(SuiteDirectory::conventional()),
            self::PhpSpec => PhpSpecSuite::conventional(),
            self::Codeception => CodeceptionSuite::conventional(),
            self::Testo => TestoSuite::conventional(),
        };
    }
}
