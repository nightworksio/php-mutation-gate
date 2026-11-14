<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Project\SuiteConfig;
use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Cli\Flow\Suite;
use NightWorksIO\MutationGate\Cli\Flow\SuiteCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/** A project's suite as the flows read it, configured by its PHPUnit config. */
final class ReadSuite
{
    public static function of(Trees $trees, Fingerprints $files, Directory $project): Suite|CannotJudge
    {
        return self::read($trees, $files, $project, SuiteCode::unkept());
    }

    /** The suite, keeping the names of each file its holds are read from for the name graph. */
    public static function keepingNames(Trees $trees, Fingerprints $files, Directory $project): Suite|CannotJudge
    {
        return self::read($trees, $files, $project, SuiteCode::namesKept());
    }

    private static function read(Trees $trees, Fingerprints $files, Directory $project, SuiteCode $code): Suite|CannotJudge
    {
        $configured = Suite::configured($project, SuiteConfig::PhpUnit);

        return $configured instanceof TestSuite ? Suite::read($trees, $files, $project, $configured, $code) : $configured;
    }
}
