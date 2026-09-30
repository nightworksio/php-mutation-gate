<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\PhpFiles;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('names a PHP file itself, and every PHP file under a directory in name order', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/B.php', '<?php');
    Scratch::write($root, 'src/A/C.php', '<?php');
    Scratch::write($root, 'src/notes.txt', 'none');
    Scratch::write($root, 'lib/D.php', '<?php');
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));

    expect(PhpFiles::in($project, Paths::of(Path::of('src'), Path::of('lib/D.php'), Path::of('src/notes.txt'))))->toBe([
        sprintf('%s/src/A/C.php', $root),
        sprintf('%s/src/B.php', $root),
        sprintf('%s/lib/D.php', $root),
    ]);
});

it('names nothing for a path that is not there', function (): void {
    expect(PhpFiles::under(DiskPath::of('/nowhere/at/all')))->toBe([]);
});

it('does not walk into a linked directory, which may lead back up into a loop', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php');
    symlink('..', sprintf('%s/src/loop', $root));
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));

    expect(PhpFiles::in($project, Paths::of(Path::of('src'))))->toBe([sprintf('%s/src/Money.php', $root)]);
});
