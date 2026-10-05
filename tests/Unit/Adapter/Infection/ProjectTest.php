<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('holds its root as the real path, its test directories and the gate\'s directory', function (): void {
    $root = Scratch::directory();
    $project = Project::at(Root::of(sprintf('%s/./', $root)), Paths::of(Path::of('tests')), Path::of('.mutation-gate'));

    expect($project->root())->toBe((string) realpath($root))
        ->and($project->tests())->toEqual(Paths::of(Path::of('tests')))
        ->and($project->own('logs/infection.json'))
        ->toBe(sprintf('%s/.mutation-gate/infection/logs/infection.json', realpath($root)));
});

it('keeps a root that is not there as it is written, less a trailing slash', function (): void {
    expect(Project::at(Root::of('/nowhere/at/all/'), Paths::none(), Path::of('.gate'))->root())->toBe('/nowhere/at/all');
});

it('places a path of the project on disk, and an absolute path where it says', function (): void {
    $project = Project::at(Root::of('/project'), Paths::none(), Path::of('.gate'));

    expect($project->absolute(Path::of('src/Money.php')))->toBe('/project/src/Money.php')
        ->and($project->absolute(Path::root()))->toBe('/project')
        ->and($project->absolute(Path::of('/elsewhere/Money.php')))->toBe('/elsewhere/Money.php');
});

it('spells a file on disk as the project does, and one outside it as it is', function (): void {
    $project = Project::at(Root::of('/project'), Paths::none(), Path::of('.gate'));

    expect($project->relative('/project/src/Money.php'))->toEqual(Path::of('src/Money.php'))
        ->and($project->relative('/projects/src/Money.php'))->toEqual(Path::of('/projects/src/Money.php'))
        ->and($project->relative('/project'))->toEqual(Path::root());
});

it('makes a directory that is not there, and leaves one that is', function (): void {
    $root = Scratch::directory();
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));

    expect($project->directory(Path::of('a/b')))->toEqual(DiskPath::of(sprintf('%s/a/b', realpath($root))))
        ->and(is_dir(sprintf('%s/a/b', $root)))->toBeTrue()
        ->and($project->directory(Path::of('a/b')))->toEqual(DiskPath::of(sprintf('%s/a/b', realpath($root))));
});

it('removes an earlier run\'s copy of a file, and makes its directory', function (): void {
    $root = (string) realpath(Scratch::directory());
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));
    Scratch::write($root, 'logs/old.json', '{}');

    expect($project->fresh(sprintf('%s/logs/old.json', $root)))->toBe(sprintf('%s/logs/old.json', $root))
        ->and(is_file(sprintf('%s/logs/old.json', $root)))->toBeFalse()
        ->and($project->fresh(sprintf('%s/new/infection.log', $root)))->toBe(sprintf('%s/new/infection.log', $root))
        ->and(is_dir(sprintf('%s/new', $root)))->toBeTrue();
});

it('cannot judge when an earlier run\'s file cannot be removed', function (): void {
    $root = (string) realpath(Scratch::directory());
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));
    Scratch::write($root, 'locked/infection.json', '{}');
    chmod(sprintf('%s/locked', $root), 0o555);
    $fresh = $project->fresh(sprintf('%s/locked/infection.json', $root));
    chmod(sprintf('%s/locked', $root), 0o755);

    expect($fresh)->toEqual(CannotJudge::because(sprintf(
        'The gate cannot remove %s/locked/infection.json, so it cannot tell what this run wrote from what an earlier one did.',
        $root,
    )));
});

it('removes a link where a file of its own goes, dangling or not, and leaves where it leads as it was', function (): void {
    $scratch = (string) realpath(Scratch::directory());
    $root = sprintf('%s/project', $scratch);
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));
    Scratch::write($scratch, 'kept.json', 'kept');
    mkdir(sprintf('%s/logs', $root), recursive: true);
    symlink(sprintf('%s/planted.json', $scratch), sprintf('%s/logs/dangling.json', $root));
    symlink(sprintf('%s/kept.json', $scratch), sprintf('%s/logs/alias.json', $root));

    expect($project->fresh(sprintf('%s/logs/dangling.json', $root)))->toBe(sprintf('%s/logs/dangling.json', $root))
        ->and($project->fresh(sprintf('%s/logs/alias.json', $root)))->toBe(sprintf('%s/logs/alias.json', $root))
        ->and(scandir(sprintf('%s/logs', $root)))->toBe(['.', '..'])
        ->and((string) file_get_contents(sprintf('%s/kept.json', $scratch)))->toBe('kept');
});

it('cannot judge when a link where a file of its own goes cannot be removed', function (): void {
    $root = (string) realpath(Scratch::directory());
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));
    mkdir(sprintf('%s/locked', $root));
    symlink(sprintf('%s/planted.json', $root), sprintf('%s/locked/infection.json', $root));
    chmod(sprintf('%s/locked', $root), 0o555);
    $fresh = $project->fresh(sprintf('%s/locked/infection.json', $root));
    chmod(sprintf('%s/locked', $root), 0o755);

    expect($fresh)->toEqual(CannotJudge::because(sprintf(
        'The gate cannot remove %s/locked/infection.json, so it cannot tell what this run wrote from what an earlier one did.',
        $root,
    )));
});
