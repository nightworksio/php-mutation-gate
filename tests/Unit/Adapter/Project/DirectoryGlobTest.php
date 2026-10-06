<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\DirectoryGlob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project holding these files, globbed from its root. */
$globbing = static function (string ...$files): DirectoryGlob {
    $project = Scratch::directory();

    foreach ($files as $file) {
        Scratch::write($project, $file, '<?php');
    }

    return DirectoryGlob::from(Root::of($project));
};

/** @return list<string> */
$expanded = static fn(DirectoryGlob $glob, string $pattern): array => array_map(
    static fn(Path $path): string => $path->value(),
    [...$glob->expanded(Path::of($pattern))],
);

it('leaves a path with no wildcard as it is, there or not', function () use ($globbing): void {
    $glob = $globbing('src/A.php');

    expect($glob->expanded(Path::of('src')))->toEqual(Paths::of(Path::of('src')))
        ->and($glob->expanded(Path::of('gone')))->toEqual(Paths::of(Path::of('gone')));
});

it('takes each directory a wildcard matches, sorted, and no file', function () use ($globbing, $expanded): void {
    $glob = $globbing('plugins/b/src/B.php', 'plugins/a/src/A.php', 'plugins/c/src', 'plugins/ab/src/C.php');

    expect($expanded($glob, 'plugins/*/src'))->toBe(['plugins/a/src', 'plugins/ab/src', 'plugins/b/src'])
        ->and($expanded($glob, 'plugins/?/src'))->toBe(['plugins/a/src', 'plugins/b/src'])
        ->and($expanded($glob, 'plugins/[a]*/src'))->toBe(['plugins/a/src', 'plugins/ab/src']);
});

it('takes no directory for a wildcard that matches none', function () use ($globbing, $expanded): void {
    expect($expanded($globbing('src/A.php'), 'plugins/*/src'))->toBe([]);
});

it('matches any depth of directories with **, none among them, each once', function () use ($globbing, $expanded): void {
    $glob = $globbing('modules/lib/A.php', 'modules/x/lib/B.php', 'modules/x/y/lib/C.php', 'modules/x/y/z/D.php');

    expect($expanded($glob, 'modules/**/lib'))->toBe(['modules/lib', 'modules/x/lib', 'modules/x/y/lib'])
        ->and($expanded($glob, 'modules/**'))->toBe(['modules', 'modules/lib', 'modules/x', 'modules/x/lib', 'modules/x/y', 'modules/x/y/lib', 'modules/x/y/z'])
        ->and($expanded($glob, '**/lib'))->toBe(['modules/lib', 'modules/x/lib', 'modules/x/y/lib']);
});

it('sorts what ** matches by path, not by depth, and takes each once however many ways it matches', function () use ($globbing, $expanded): void {
    $glob = $globbing('m/a/deep/lib/A.php', 'm/b/lib/B.php');

    expect($expanded($glob, 'm/**/lib'))->toBe(['m/a/deep/lib', 'm/b/lib'])
        ->and($expanded($glob, 'm/**/**/lib'))->toBe(['m/a/deep/lib', 'm/b/lib']);
});
