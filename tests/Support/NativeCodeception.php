<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Codeception\CodeceptionFramework;
use NightWorksIO\MutationGate\Adapter\Codeception\Installation;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\TestFiles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Native\TestLayout;

/** Codeception as the native runner drives it in a project. */
final class NativeCodeception
{
    /** Codeception installed in the project's vendor directory, its tests in the project's suite directories. */
    public static function in(Project $project): CodeceptionFramework
    {
        return self::framework($project->root(), $project->vendor(), new TestFiles($project));
    }

    /** Codeception in a root, installed in this vendor directory, its tests laid out so, as the Cli builds it. */
    public static function framework(string $root, Path $vendor, TestLayout $tests): CodeceptionFramework
    {
        return new CodeceptionFramework(Installation::at($root, $vendor), $tests);
    }
}
