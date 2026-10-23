<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Installation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\PhpUnitFramework;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/** PHPUnit as the native runner drives it in a project. */
final class NativePhpUnit
{
    /** PHPUnit installed in the project's vendor directory, its tests in the project's test directories. */
    public static function in(Project $project): PhpUnitFramework
    {
        return new PhpUnitFramework(Installation::at($project->root(), $project->tests(), $project->vendor()));
    }

    /** PHPUnit in a root, its tests in these directories, installed in this vendor directory, as the Cli builds it. */
    public static function framework(string $root, Paths $tests, Path $vendor): PhpUnitFramework
    {
        return new PhpUnitFramework(Installation::at($root, $tests, $vendor));
    }
}
