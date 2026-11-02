<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\Native\TestFiles;
use NightWorksIO\MutationGate\Adapter\Testo\Installation;
use NightWorksIO\MutationGate\Adapter\Testo\TestoFramework;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Native\TestLayout;

/** Testo as the native runner drives it in a project. */
final class NativeTesto
{
    /** Testo installed in the project's vendor directory, its tests in the project's suite directories. */
    public static function in(Project $project): TestoFramework
    {
        return self::framework($project->root(), $project->vendor(), new TestFiles($project));
    }

    /** Testo in a root, installed in this vendor directory, its tests laid out so, as the Cli builds it. */
    public static function framework(string $root, Path $vendor, TestLayout $tests): TestoFramework
    {
        return new TestoFramework(Installation::at($root, $vendor), $tests);
    }
}
