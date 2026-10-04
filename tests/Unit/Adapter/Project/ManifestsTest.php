<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\Manifests;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('lists every path the root composer.json autoloads, once, in its order', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', (string) json_encode([
        'autoload' => [
            'psr-4' => ['App\\' => 'app/', 'Lib\\' => ['lib/', 'src/'], 'Bad\\' => 3],
            'psr-0' => ['Old_' => 'old/'],
            'classmap' => ['database/', 'app/'],
            'files' => ['helpers.php'],
        ],
        'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
    ]));
    $paths = Manifests::in(Root::of($project))->autoloaded();

    expect($paths instanceof Paths
        ? array_map(static fn(Path $path): string => $path->value(), [...$paths])
        : $paths)
        ->toBe(['app', 'lib', 'src', 'old', 'database', 'helpers.php']);
});

it('autoloads nothing without a composer.json, or with one that autoloads nothing', function (string $manifest): void {
    $project = Scratch::directory();

    if ($manifest !== '') {
        Scratch::write($project, 'composer.json', $manifest);
    }

    expect(Manifests::in(Root::of($project))->autoloaded())->toEqual(Paths::none());
})->with(['', '{}', '{"autoload": {"psr-4": "src"}}']);

it('cannot judge a root composer.json that is not a JSON object', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '"src"');

    expect(Manifests::in(Root::of($project))->autoloaded())->toEqual(CannotJudge::because('composer.json is not a JSON object.'));
});

it('reads a floor of 0 with its reason as exempt', function (): void {
    $project = Scratch::directory();
    Scratch::write(
        $project,
        'gen/composer.json',
        '{"extra": {"mutation-gate": {"floor": 0, "floorReason": "Generated"}}}',
    );
    $trees = Manifests::in(Root::of($project))->trees(Paths::of(Path::of('gen')));

    expect($trees instanceof Trees
        ? array_map(static fn(Tree $tree): mixed => $tree->declared(), [...$trees])
        : $trees)->toEqual([Exempt::because('Generated')]);
});

it('reads the ends of the range as floors', function (int|float $floor, Floor $read): void {
    $project = Scratch::directory();
    Scratch::write(
        $project,
        'composer.json',
        sprintf('{"extra": {"mutation-gate": {"floor": %s, "floorReason": "x"}}}', $floor),
    );
    $trees = Manifests::in(Root::of($project))->trees(Paths::of(Path::of('src')));

    expect($trees instanceof Trees
        ? array_map(static fn(Tree $tree): mixed => $tree->declared(), [...$trees])
        : $trees)->toEqual([$read]);
})->with([
    'the top' => [100, Floor::of(100)],
    'just above none' => [0.01, Floor::ofHundredths(1)],
]);

it('cannot judge a floor a manifest declares wrongly', function (string $settings, string $why): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/composer.json', sprintf('{"extra": {"mutation-gate": %s}}', $settings));

    expect(Manifests::in(Root::of($project))->trees(Paths::of(Path::of('src'))))->toEqual(CannotJudge::because($why));
})->with([
    'a string' => ['{"floor": "80"}', 'src/composer.json: extra.mutation-gate.floor is not a number from 0 to 100.'],
    '0 without a reason' => [
        '{"floor": 0}',
        'src/composer.json declares extra.mutation-gate.floor as 0 without a floorReason beside it.',
    ],
    'a security floor, which is no package\'s' => [
        '{"securityFloor": 90}',
        "src/composer.json declares extra.mutation-gate.securityFloor, and it is not a package's manifest.\nA package's security set has one floor: declare it in the composer.json of the package.",
    ],
]);

it('gives the root package the floor the root composer.json declares for its security set', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"extra": {"mutation-gate": {"securityFloor": 97.5}}}');
    Scratch::write($project, 'src/Domain/composer.json', '{"extra": {"mutation-gate": {"floor": 90}}}');
    $trees = Manifests::in(Root::of($project))->trees(Paths::of(Path::of('src/Domain'), Path::of('lib')));

    expect($trees instanceof Trees
        ? array_map(static fn(Tree $tree): mixed => $tree->package(), [...$trees])
        : $trees)->toEqual([
            Package::at(Path::root())->withSecurityFloor(Floor::of(97.5)),
            Package::at(Path::root())->withSecurityFloor(Floor::of(97.5)),
        ]);
});

it('cannot judge the trees where the root composer.json declares a security floor wrongly', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"extra": {"mutation-gate": {"securityFloor": "all"}}}');

    expect(Manifests::in(Root::of($project))->trees(Paths::of(Path::of('src'))))
        ->toEqual(CannotJudge::because('composer.json: extra.mutation-gate.securityFloor is not a number from 0 to 100.'));
});
