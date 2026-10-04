<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\ReferencedFiles;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Each referenced file's digest, or `missing`, by its path, in a project at this root.
 *
 * @return array<string, string>
 */
function referencedIn(string $root, Path ...$references): array
{
    $digests = [];

    foreach (ReferencedFiles::under(Root::of($root))->digests(Paths::of(...$references)) as $path => $digest) {
        $digests[$path->value()] = $digest instanceof Digest ? $digest->value() : 'missing';
    }

    return $digests;
}

it('digests each file referenced, and each file under a directory referenced, by its contents, and no directory, nor one it links to', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpstan-baseline.neon', 'parameters: {}');
    Scratch::write($project, 'stubs/a.stub', '<?php class A {}');
    Scratch::write($project, 'stubs/deep/b.stub', '<?php class B {}');
    mkdir(sprintf('%s/stubs/empty', $project));
    symlink(sprintf('%s/stubs/deep', $project), sprintf('%s/stubs/linked', $project));

    expect(referencedIn($project, Path::of('phpstan-baseline.neon'), Path::of('stubs')))->toEqual([
        'phpstan-baseline.neon' => hash('sha256', 'parameters: {}'),
        'stubs/a.stub' => hash('sha256', '<?php class A {}'),
        'stubs/deep/b.stub' => hash('sha256', '<?php class B {}'),
    ]);
});

it('names a file it cannot find as missing', function (): void {
    $project = Scratch::directory();

    expect(ReferencedFiles::under(Root::of($project))->digests(Paths::of(Path::of('gone.neon')))->at(Path::of('gone.neon'), Digest::sha256Of('')))
        ->toEqual(Missing::at(Path::of('gone.neon')));
});

it('names the same files alike from two roots', function (): void {
    $here = Scratch::directory();
    $there = Scratch::directory();

    foreach ([$here, $there] as $project) {
        Scratch::write($project, 'bootstrap.php', '<?php');
        Scratch::write($project, 'stubs/a.stub', '<?php class A {}');
    }

    expect(referencedIn($here, Path::of('bootstrap.php'), Path::of('stubs')))
        ->toBe(referencedIn($there, Path::of('bootstrap.php'), Path::of('stubs')));
});

it('reads a file outside the root where the analyser names it, by the path it was named by', function (): void {
    $project = Scratch::directory();
    $elsewhere = Scratch::directory();
    Scratch::write($elsewhere, 'shared.neon', 'includes: []');

    expect(referencedIn($project, Path::of(sprintf('%s/shared.neon', $elsewhere))))
        ->toBe([sprintf('%s/shared.neon', $elsewhere) => hash('sha256', 'includes: []')]);
});
