<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Config\Sampling;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('samples by default at 95 percent, for 2 points either side, running at least 30 units, from no seed of its own', function (): void {
    $sampling = Layer::standard()->reach()->sampling();

    expect([$sampling->confidence(), $sampling->margin(), $sampling->minUnits(), $sampling->seed()])
        ->toEqual([Confidence::NinetyFive, 2.0, 30, NotGiven::value()])
        ->and(Sampling::of()->margin())->toBe(2.0)
        ->and(Sampling::of()->minUnits())->toBe(30)
        ->and(Sampling::of()->confidence())->toBe(Confidence::NinetyFive);
});

it('lays each sampling key a later layer sets over an earlier one\'s, key by key', function (): void {
    $earlier = Layer::of(Reach::of(sampling: Sampling::of(confidence: Confidence::NinetyNine, margin: 1)));
    $later = Layer::of(Reach::of(sampling: Sampling::of(margin: 1.5, minUnits: 50, seed: 'release')));
    $sampling = $earlier->over($later)->reach()->sampling();

    expect([$sampling->confidence(), $sampling->margin(), $sampling->minUnits(), $sampling->seed()])
        ->toEqual([Confidence::NinetyNine, 1.5, 50, 'release'])
        ->and($earlier->over(Layer::none())->reach()->sampling()->margin())->toBe(1.0)
        ->and(Layer::none()->over($later)->reach()->sampling()->seed())->toBe('release');
});

it('writes only the sampling keys a layer sets, and the builder calls for them', function (): void {
    $layer = Layer::of(Reach::of(sampling: Sampling::of(Confidence::Ninety, 1.5, 40, 'release')));
    $php = $layer->php(ProjectRoot::origin())->code();

    expect($layer->written(ProjectRoot::origin())->line())
        ->toBe('{"sampling":{"confidence":90,"margin":1.5,"minUnits":40,"seed":"release"}}')
        ->and($php)->toContain('Sampling::confidence(90)')
        ->and($php)->toContain('Sampling::margin(1.5)')
        ->and($php)->toContain('Sampling::minUnits(40)')
        ->and($php)->toContain("Sampling::seed('release')")
        ->and(Layer::of(Reach::of(sampling: Sampling::of(margin: 3)))->php(ProjectRoot::origin())->code())
        ->toContain('Sampling::margin(3)')
        ->and(Layer::of(Reach::of(sampling: Sampling::of(seed: new Absent())))->written(ProjectRoot::origin())->line())
        ->toBe('{}')
        ->and(Layer::of(Reach::of(sampling: Sampling::of()))->php(ProjectRoot::origin())->code())
        ->not->toContain('Sampling::');
});

it('reads the sampling keys a config writes', function (): void {
    $sampling = Configs::settings([
        'runner' => 'pest',
        'sampling' => ['confidence' => 99, 'margin' => 0.5, 'minUnits' => 1, 'seed' => 'release'],
    ])->reach()->sampling();

    expect([$sampling->confidence(), $sampling->margin(), $sampling->minUnits(), $sampling->seed()])
        ->toEqual([Confidence::NinetyNine, 0.5, 1, 'release'])
        ->and(Configs::settings(['runner' => 'pest', 'sampling' => ['margin' => 10]])->reach()->sampling()->margin())
        ->toBe(10.0);
});

it('refuses a confidence it does not offer, a margin of none or over 10, fewer than one unit, and an empty seed', function (): void {
    expect(Configs::problems(Configs::layer([
        'sampling' => ['confidence' => 80, 'margin' => 0, 'minUnits' => 0, 'seed' => ''],
    ])))->toBe([
        'sampling.confidence: expected 90, 95 or 99, got 80',
        'sampling.margin: expected a number above 0 and at most 10, got 0',
        'sampling.minUnits: expected an integer of at least 1, got 0',
        'sampling.seed: expected a seed, any text, got ""',
    ])
        ->and(Configs::problems(Configs::layer(['sampling' => ['confidence' => '95', 'margin' => 10.5]])))->toBe([
            'sampling.confidence: expected 90, 95 or 99, got "95"',
            'sampling.margin: expected a number above 0 and at most 10, got 10.5',
        ]);
});
