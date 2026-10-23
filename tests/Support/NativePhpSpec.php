<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Installation;
use NightWorksIO\MutationGate\Adapter\PhpSpec\PhpSpecFramework;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Native\TestLayout;

/** PhpSpec as the native runner drives it in a project. */
final class NativePhpSpec
{
    /** PhpSpec installed in the project's vendor directory, its specs in the project's spec directories. */
    public static function in(Project $project): PhpSpecFramework
    {
        return self::framework($project->root(), $project->vendor(), new TestFiles($project));
    }

    /** PhpSpec in a root, installed in this vendor directory, its specs laid out so, as the Cli builds it. */
    public static function framework(string $root, Path $vendor, TestLayout $tests): PhpSpecFramework
    {
        return new PhpSpecFramework(Installation::at($root, $vendor), $tests);
    }
}
