<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\PhpSpecConfig;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

/**
 * The config a project's tests are declared in: PhpSpec's, for the PhpSpec
 * runner, and PHPUnit's, for every other runner, which all run PHPUnit's
 * tests.
 */
enum SuiteConfig
{
    case PhpUnit;
    case PhpSpec;

    /** The config the runner of this name reads its tests from. */
    public static function ofRunner(string $runner): self
    {
        return $runner === BuiltinRunner::PhpSpec->value ? self::PhpSpec : self::PhpUnit;
    }

    /** Where the config would be in a directory, in the order its framework looks for it. */
    public function candidatesIn(Path $directory): Paths
    {
        return match ($this) {
            self::PhpUnit => PhpUnitConfig::candidatesIn($directory),
            self::PhpSpec => PhpSpecConfig::candidatesIn($directory),
        };
    }

    /** The suite a config, read from this file, declares, its wildcards expanded by a glob. */
    public function declaredIn(Contents $config, Path $file, DirectoryGlob $glob): TestSuite|CannotJudge
    {
        return match ($this) {
            self::PhpUnit => PhpUnitSuite::declaredIn($config, $file, $glob),
            self::PhpSpec => PhpSpecSuite::declaredIn($config, $file),
        };
    }

    /** The suite where the project has no config. */
    public function conventional(): TestSuite
    {
        return match ($this) {
            self::PhpUnit => TestSuite::conventional(SuiteDirectory::conventional()),
            self::PhpSpec => PhpSpecSuite::conventional(),
        };
    }
}
