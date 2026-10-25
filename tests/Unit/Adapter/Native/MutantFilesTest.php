<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\MutantFiles;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\NativePhpUnit;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The files of a run of no test in this project, its mutant Money unchanged. */
function mutantFilesStartingUp(Project $project): MutantFiles
{
    $selection = NativePhpUnit::in($project)->selection(TestIds::none());
    $files = $selection instanceof Selection ? MutantFiles::startingUp($project, Path::of('src/Money.php'), $selection) : $selection;

    return $files instanceof MutantFiles ? $files : throw new LogicException('Money starts up.');
}

it('writes a run\'s results and guard files empty, over what an earlier run left in them', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nreturn 1 + 1;\n");
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit);
    $earlier = mutantFilesStartingUp($project);
    file_put_contents($earlier->results(), "passed Tests\\MoneyTest::adds\n");
    file_put_contents($earlier->guard(), "served\n");

    $files = mutantFilesStartingUp($project);

    expect([file_get_contents($files->results()), file_get_contents($files->guard())])->toBe(['', '']);
});
