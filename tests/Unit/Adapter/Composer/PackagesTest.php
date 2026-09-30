<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Composer\Disk;
use NightWorksIO\MutationGate\Adapter\Composer\Packages;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Project;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The packages of a project holding these files, found by these globs.
 *
 * @param array<string, string> $files
 * @param list<string>          $globs
 */
function packagesOf(array $files, array $globs = []): Packages
{
    $packages = Packages::in(Disk::at(Root::of(Project::with($files))), $globs);

    return $packages instanceof Packages ? $packages : throw new RuntimeException('The fixture has no packages.');
}

it('is the project at the root alone where nothing names another', function (): void {
    $packages = packagesOf(['composer.json' => '{"name": "acme/app"}']);

    expect($packages->directories())->toEqual([Path::root()])
        ->and($packages->holding(Path::of('src/Money.php'))->path())->toEqual(Path::root());
});

it('is the project at the root even where it has no composer.json', function (): void {
    expect(packagesOf([])->directories())->toEqual([Path::root()]);
});

it('finds each directory a packages glob matches that has a composer.json', function (): void {
    $packages = packagesOf([
        'composer.json' => '{}',
        'packages/money/composer.json' => '{"name": "acme/money"}',
        'packages/clock/composer.json' => '{"name": "acme/clock"}',
        'packages/docs/README.md' => '# Docs',
    ], ['packages/*']);

    expect($packages->directories())->toEqual([Path::root(), Path::of('packages/clock'), Path::of('packages/money')]);
});

it('finds each path repository of the root that has a composer.json and a PHPUnit config', function (string $config): void {
    $packages = packagesOf([
        'composer.json' => '{"repositories": [{"type": "path", "url": "libs/*"}, {"type": "vcs", "url": "mirrors/vcs"}]}',
        sprintf('libs/money/%s', $config) => '<phpunit/>',
        'libs/money/composer.json' => '{"name": "acme/money"}',
        'libs/clock/composer.json' => '{"name": "acme/clock"}',
        'libs/docs/phpunit.xml' => '<phpunit/>',
        'mirrors/vcs/composer.json' => '{}',
        'mirrors/vcs/phpunit.xml' => '<phpunit/>',
    ]);

    expect($packages->directories())->toEqual([Path::root(), Path::of('libs/money')]);
})->with(['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml']);

it('makes each package depend on the others its manifest requires by name', function (): void {
    $packages = packagesOf([
        'composer.json' => '{"name": "acme/app", "require": {"acme/money": "*"}}',
        'packages/core/composer.json' => '{"name": "acme/core", "require": {"php": "^8.5"}}',
        'packages/money/composer.json' => '{"name": "acme/money", "require": {"acme/core": "*"}, "require-dev": {"acme/clock": "*"}}',
        'packages/clock/composer.json' => '{"name": "acme/clock"}',
    ], ['packages/*']);

    expect($packages->holding(Path::root())->dependencies())->toEqual(Paths::of(Path::of('packages/money')))
        ->and($packages->holding(Path::of('packages/money/src/Money.php'))->dependencies())
        ->toEqual(Paths::of(Path::of('packages/core'), Path::of('packages/clock')))
        ->and($packages->holding(Path::of('packages/core'))->dependencies())->toEqual(Paths::none());
});

it('places a path in the innermost package around it', function (): void {
    $packages = packagesOf([
        'composer.json' => '{}',
        'packages/money/composer.json' => '{}',
        'packages/money/plugins/composer.json' => '{}',
    ], ['packages/money/plugins', 'packages/*']);

    expect($packages->holding(Path::of('packages/money/plugins/src/Stripe.php'))->path())->toEqual(Path::of('packages/money/plugins'))
        ->and($packages->holding(Path::of('packages/money/src/Money.php'))->path())->toEqual(Path::of('packages/money'))
        ->and($packages->holding(Path::of('packages/moneyed/src/Money.php'))->path())->toEqual(Path::root());
});

it('counts a package found twice once', function (): void {
    $packages = packagesOf(['composer.json' => '{}', 'packages/money/composer.json' => '{}'], ['packages/*', 'packages/money']);

    expect($packages->directories())->toEqual([Path::root(), Path::of('packages/money')]);
});

it('cannot judge a manifest it cannot read', function (string $path, string $manifest): void {
    $project = Project::with(['composer.json' => '{}', $path => $manifest]);

    expect(Packages::in(Disk::at(Root::of($project)), ['packages/*']))->toBeInstanceOf(CannotJudge::class);
})->with([
    'the root' => ['composer.json', '{'],
    'a package' => ['packages/money/composer.json', '['],
]);
