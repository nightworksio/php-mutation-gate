<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Infection\Targets;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project with src/Money.php, src/Held.php, src/Heldover/Rest.php and lib/Tax.php. */
$project = static function (): Project {
    $root = Scratch::directory();

    foreach (['src/Money.php', 'src/Held.php', 'src/Heldover/Rest.php', 'lib/Tax.php'] as $file) {
        Scratch::write($root, $file, '<?php');
    }

    return Project::at(Root::of($root), Paths::none(), Path::of('.gate'));
};

it('gives Infection the paths asked for, in the directories they are in', function () use ($project): void {
    $at = $project();
    $targets = Targets::of($at, Paths::of(Path::of('src'), Path::of('lib/Tax.php'), Path::of('src/Money.php')), Paths::none());

    expect($targets->paths())->toBe([
        sprintf('%s/src', $at->root()),
        sprintf('%s/lib/Tax.php', $at->root()),
        sprintf('%s/src/Money.php', $at->root()),
    ])->and($targets->directories())->toBe([sprintf('%s/src', $at->root()), sprintf('%s/lib', $at->root())]);
});

it('gives Infection each PHP file left, one by one, where a run leaves paths out', function () use ($project): void {
    $at = $project();
    $targets = Targets::of($at, Paths::of(Path::of('src')), Paths::of(Path::of('src/Held.php')));

    expect($targets->paths())->toBe([
        sprintf('%s/src/Heldover/Rest.php', $at->root()),
        sprintf('%s/src/Money.php', $at->root()),
    ])->and($targets->directories())->toBe([sprintf('%s/src', $at->root())]);
});

it('leaves out every file under a directory left out', function () use ($project): void {
    $at = $project();

    expect(Targets::of($at, Paths::of(Path::of('src')), Paths::of(Path::of('src/Heldover')))->paths())->toBe([
        sprintf('%s/src/Held.php', $at->root()),
        sprintf('%s/src/Money.php', $at->root()),
    ]);
});

it('names no source directory inside another, as Infection would find each file in it once for each', function () use ($project): void {
    $at = $project();
    $targets = Targets::of(
        $at,
        Paths::of(Path::of('src/Heldover/Rest.php'), Path::of('src/Money.php'), Path::of('lib/Tax.php')),
        Paths::none(),
    );

    expect($targets->directories())->toBe([sprintf('%s/src', $at->root()), sprintf('%s/lib', $at->root())]);
});
