<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Composer\Disk;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Project;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads what a file holds, and names a file that is not there', function (): void {
    $disk = Disk::at(Root::of(Project::with(['composer.json' => '{}', 'src/Money.php' => '<?php'])));

    expect($disk->read(Path::of('composer.json')))->toBe('{}')
        ->and($disk->read(Path::of('src/Money.php')))->toBe('<?php')
        ->and($disk->read(Path::of('src')))->toEqual(Missing::at(Path::of('src')))
        ->and($disk->read(Path::of('missing.json')))->toEqual(Missing::at(Path::of('missing.json')));
});

it('cannot judge a file it cannot read', function (): void {
    $root = Project::with(['composer.json' => '{}']);
    chmod(sprintf('%s/composer.json', $root), 0o000);
    $read = Disk::at(Root::of($root))->read(Path::of('composer.json'));
    chmod(sprintf('%s/composer.json', $root), 0o644);

    expect($read)->toEqual(CannotJudge::because('composer.json could not be read.'));
});

it('reads the manifest of a directory, and names one that is not there', function (): void {
    $disk = Disk::at(Root::of(Project::with(['modules/billing/composer.json' => '{"name": "acme/billing"}', 'broken/composer.json' => '['])));
    $manifest = $disk->manifestIn(Path::of('modules/billing'));

    expect($manifest instanceof Manifest ? $manifest->name() : $manifest)->toBe('acme/billing')
        ->and($disk->manifestIn(Path::root()))->toEqual(Missing::at(Path::of('composer.json')))
        ->and($disk->manifestIn(Path::of('broken')))->toEqual(CannotJudge::because('broken/composer.json is not a JSON object.'));
});

it('knows which paths are files', function (): void {
    $disk = Disk::at(Root::of(Project::with(['src/Money.php' => '<?php'])));

    expect($disk->isFile(Path::of('src/Money.php')))->toBeTrue()
        ->and($disk->isFile(Path::of('src')))->toBeFalse()
        ->and($disk->isFile(Path::of('src/Clock.php')))->toBeFalse();
});

it('finds the directories a shell glob matches, spelt from the root', function (): void {
    $disk = Disk::at(Root::of(Project::with([
        'packages/money/composer.json' => '{}',
        'packages/clock/composer.json' => '{}',
        'packages/README.md' => '# Packages',
    ])));

    expect($disk->directories('packages/*'))->toEqual([Path::of('packages/clock'), Path::of('packages/money')])
        ->and($disk->directories('libs/*'))->toBe([]);
});

it('finds the directories a glob of the config matches, from the directory before its first wildcard, never through a link', function (): void {
    $root = Project::with([
        'packages/money/composer.json' => '{}',
        'packages/clock/src/composer.json' => '{}',
        'packages/group/rates/composer.json' => '{}',
        'packages/README.md' => '# Packages',
        'libs/money/composer.json' => '{}',
    ]);
    symlink(sprintf('%s/packages', $root), sprintf('%s/packages/group/loop', $root));
    $disk = Disk::at(Root::of($root));

    expect($disk->directoriesMatching(Glob::of('packages/*')))
        ->toEqual([Path::of('packages/clock'), Path::of('packages/group'), Path::of('packages/money')])
        ->and($disk->directoriesMatching(Glob::of('packages/**/rates')))->toEqual([Path::of('packages/group/rates')])
        ->and($disk->directoriesMatching(Glob::of('*/money')))->toEqual([Path::of('libs/money'), Path::of('packages/money')])
        ->and($disk->directoriesMatching(Glob::of('libs/money')))->toEqual([Path::of('libs/money')])
        ->and($disk->directoriesMatching(Glob::of('packages/group/*')))
        ->toEqual([Path::of('packages/group/loop'), Path::of('packages/group/rates')])
        ->and($disk->directoriesMatching(Glob::of('modules/*')))->toBe([]);
});

it('finds the files a shell glob matches, spelt from the root', function (): void {
    $disk = Disk::at(Root::of(Project::with([
        'modules/billing/composer.json' => '{}',
        'modules/shop/composer.json' => '{}',
        'modules/shop/src/composer.json/.gitkeep' => '',
    ])));

    expect($disk->files('modules/*/composer.json'))->toEqual([Path::of('modules/billing/composer.json'), Path::of('modules/shop/composer.json')])
        ->and($disk->files('modules/*/src/composer.json'))->toBe([]);
});
