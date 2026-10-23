<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Installation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\PhpUnitFramework;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Native\TestLayout;

/** PHPUnit as the native runner drives it in a project. */
final class NativePhpUnit
{
    /** PHPUnit installed in the project's vendor directory, its tests in the project's test directories. */
    public static function in(Project $project): PhpUnitFramework
    {
        return self::framework($project->root(), $project->vendor(), new TestFiles($project));
    }

    /** PHPUnit in a root, installed in this vendor directory, its tests laid out so, as the Cli builds it. */
    public static function framework(string $root, Path $vendor, TestLayout $tests): PhpUnitFramework
    {
        return new PhpUnitFramework(Installation::at($root, $vendor), $tests);
    }
}
