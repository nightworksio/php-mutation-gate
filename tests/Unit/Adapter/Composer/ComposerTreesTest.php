<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Composer\ComposerTrees;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Project;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('finds a tree for each path the root manifest autoloads, each with the floor of the nearest manifest that declares one', function (): void {
    $root = Project::with([
        'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src/", "Lib\\\\": "lib/"}, "classmap": ["src/"]}, "extra": {"mutation-gate": {"floor": 80, "newCodeFloor": 90}}}',
        'src/composer.json' => '{"extra": {"mutation-gate": {"floor": 100}}}',
        'lib/composer.json' => '{"extra": {"mutation-gate": {"newCodeFloor": 95}}}',
    ]);
    $package = Package::at(Path::root());

    expect(ComposerTrees::at($root, ['composer.json'], [])->trees())->toEqual(Trees::of(
        Tree::at(Path::of('src'), Floor::of(100), $package)->withNewCodeFloor(Floor::of(90)),
        Tree::at(Path::of('lib'), Floor::of(80), $package)->withNewCodeFloor(Floor::of(95)),
    ));
});

it('finds the trees of every module manifest a glob matches', function (): void {
    $root = Project::with([
        'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "app/"}}}',
        'app-modules/billing/composer.json' => '{"autoload": {"psr-4": {"Billing\\\\": "src/"}}, "extra": {"mutation-gate": {"floor": 0, "floorReason": "Being rewritten"}}}',
        'app-modules/shop/composer.json' => '{"autoload": {"psr-4": {"Shop\\\\": "src/"}}, "extra": {"mutation-gate": {"newCodeFloor": 95}}}',
    ]);
    $package = Package::at(Path::root());

    expect(ComposerTrees::at($root, ['composer.json', 'app-modules/*/composer.json'], [])->trees())->toEqual(Trees::of(
        Tree::at(Path::of('app'), Undeclared::floor(), $package),
        Tree::at(Path::of('app-modules/billing/src'), Exempt::because('Being rewritten'), $package),
        Tree::at(Path::of('app-modules/shop/src'), Undeclared::floor(), $package)->withNewCodeFloor(Floor::of(95)),
    ));
});

it('finds the trees of every package in the package, depending on what it requires', function (): void {
    $root = Project::with([
        'composer.json' => '{"name": "acme/app", "autoload": {"psr-4": {"App\\\\": "src/"}}}',
        'packages/core/composer.json' => '{"name": "acme/core", "autoload": {"psr-4": {"Core\\\\": "src/"}}}',
        'packages/money/composer.json' => '{"name": "acme/money", "require": {"acme/core": "*"}, "autoload": {"psr-4": {"Money\\\\": "src/"}}}',
    ]);

    expect(ComposerTrees::at($root, ['composer.json'], ['packages/*'])->trees())->toEqual(Trees::of(
        Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())),
        Tree::at(Path::of('packages/core/src'), Undeclared::floor(), Package::at(Path::of('packages/core'))),
        Tree::at(Path::of('packages/money/src'), Undeclared::floor(), Package::at(Path::of('packages/money'))->dependingOn(Path::of('packages/core'))),
    ));
});

it('finds no tree where no manifest autoloads anything', function (): void {
    expect(ComposerTrees::at(Project::with(['composer.json' => '{}']), ['composer.json'], [])->trees())->toEqual(Trees::none())
        ->and(ComposerTrees::at(Project::with([]), ['composer.json'], [])->trees())->toEqual(Trees::none());
});

it('cannot judge the trees where a manifest cannot be read or declares a floor it cannot use', function (string $root, string $path, string $manifest, string $said): void {
    $project = Project::with(['composer.json' => $root, $path => $manifest]);
    $trees = ComposerTrees::at($project, ['composer.json', 'modules/*/composer.json'], ['packages/*'])->trees();

    expect($trees)->toBeInstanceOf(CannotJudge::class)
        ->and($trees instanceof CannotJudge ? $trees->why() : '')->toBe($said);
})->with([
    'the root' => ['{}', 'composer.json', '{', 'composer.json is not a JSON object.'],
    'a package' => ['{}', 'packages/money/composer.json', '[', 'packages/money/composer.json is not a JSON object.'],
    'a module' => ['{}', 'modules/billing/composer.json', '1', 'modules/billing/composer.json is not a JSON object.'],
    'a manifest above a tree' => [
        '{"autoload": {"psr-4": {"App\\\\": "src/Domain/"}}}',
        'src/composer.json',
        '{',
        'src/composer.json is not a JSON object.',
    ],
    'a floor' => [
        '{}',
        'composer.json',
        '{"autoload": {"psr-4": {"App\\\\": "src/"}}, "extra": {"mutation-gate": {"floor": 0}}}',
        'composer.json declares extra.mutation-gate.floor as 0 without a floorReason beside it.',
    ],
    'a floor for new lines' => [
        '{}',
        'composer.json',
        '{"autoload": {"psr-4": {"App\\\\": "src/"}}, "extra": {"mutation-gate": {"newCodeFloor": 101}}}',
        'composer.json: extra.mutation-gate.newCodeFloor is not a number from 0 to 100.',
    ],
]);
