<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\Manifests;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
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
    $paths = Manifests::in($project)->autoloaded();

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

    expect(Manifests::in($project)->autoloaded())->toEqual(Paths::none());
})->with(['', '{}', '{"autoload": {"psr-4": "src"}}']);

it('cannot judge a root composer.json that is not a JSON object', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '"src"');

    expect(Manifests::in($project)->autoloaded())->toEqual(CannotJudge::because('composer.json is not a JSON object.'));
});

it('reads a floor of 0 with its reason as exempt', function (): void {
    $project = Scratch::directory();
    Scratch::write(
        $project,
        'gen/composer.json',
        '{"extra": {"mutation-gate": {"floor": 0, "floorReason": "Generated"}}}',
    );
    $trees = Manifests::in($project)->trees(Paths::of(Path::of('gen')));

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
    $trees = Manifests::in($project)->trees(Paths::of(Path::of('src')));

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

    expect(Manifests::in($project)->trees(Paths::of(Path::of('src'))))->toEqual(CannotJudge::because($why));
})->with([
    'a string' => [
        '{"floor": "80"}',
        'src/composer.json declares extra.mutation-gate.floor as "80", which is not a number from 0 to 100.',
    ],
    'below 0' => [
        '{"floor": -1}',
        'src/composer.json declares extra.mutation-gate.floor as -1, which is not a number from 0 to 100.',
    ],
    'above 100' => [
        '{"floor": 100.5}',
        'src/composer.json declares extra.mutation-gate.floor as 100.5, which is not a number from 0 to 100.',
    ],
    '0 without a reason' => [
        '{"floor": 0}',
        'src/composer.json declares a floor of 0 without the reason extra.mutation-gate.floorReason gives it.',
    ],
    '0 with an empty reason' => [
        '{"floor": 0, "floorReason": ""}',
        'src/composer.json declares a floor of 0 without the reason extra.mutation-gate.floorReason gives it.',
    ],
]);
