<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Config\Sampling;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\AutoSample;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\SampledRun;
use NightWorksIO\MutationGate\Core\Time\Seconds;
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
        ->and($earlier->over(Layer::of())->reach()->sampling()->margin())->toBe(1.0)
        ->and(Layer::of()->over($later)->reach()->sampling()->seed())->toBe('release');
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

it('samples of its own accord nowhere by default, and would only on a pull request\'s run', function (): void {
    $sampling = Layer::standard()->reach()->sampling();

    expect($sampling->auto())->toEqual(AutoSample::off())
        ->and([...$sampling->autoRuns()])->toBe([SampledRun::PullRequests])
        ->and($sampling->samplesOfItself(SampledRun::PullRequests))->toBeTrue()
        ->and($sampling->samplesOfItself(SampledRun::MergeGroups))->toBeFalse()
        ->and($sampling->samplesOfItself(SampledRun::Branches))->toBeFalse()
        ->and(Sampling::of()->auto())->toEqual(AutoSample::off())
        ->and(Sampling::of()->samplesOfItself(SampledRun::PullRequests))->toBeTrue();
});

it('lays sampling.auto and sampling.autoRuns a later layer sets over an earlier one\'s', function (): void {
    $earlier = Sampling::of(auto: AutoSample::aboveMutants(500), autoRuns: Listed::of(SampledRun::Branches));
    $later = Sampling::of(auto: AutoSample::aboveRunnerTime(Seconds::of(3600)));
    $laid = $earlier->over($later);

    expect($laid->auto())->toEqual(AutoSample::aboveRunnerTime(Seconds::of(3600)))
        ->and($laid->samplesOfItself(SampledRun::Branches))->toBeTrue()
        ->and($laid->samplesOfItself(SampledRun::PullRequests))->toBeFalse()
        ->and($earlier->over(Sampling::of())->auto())->toEqual(AutoSample::aboveMutants(500));
});

it('writes sampling.auto and sampling.autoRuns, and the builder calls for them', function (): void {
    $count = Layer::of(Reach::of(sampling: Sampling::of(
        auto: AutoSample::aboveMutants(20000),
        autoRuns: Listed::of(SampledRun::PullRequests, SampledRun::MergeGroups),
    )));
    $duration = Layer::of(Reach::of(sampling: Sampling::of(auto: AutoSample::aboveRunnerTime(Seconds::of(7200)))));
    $off = Layer::of(Reach::of(sampling: Sampling::of(auto: AutoSample::off(), autoRuns: Listed::of(SampledRun::Branches))));
    $runs = Layer::of(Reach::of(sampling: Sampling::of(autoRuns: Listed::of(SampledRun::Branches))));

    expect($count->written(ProjectRoot::origin())->line())
        ->toBe('{"sampling":{"auto":20000,"autoRuns":["pullRequests","mergeGroups"]}}')
        ->and($count->php(ProjectRoot::origin())->code())->toContain('Sampling::auto(20000)')
        ->and($count->php(ProjectRoot::origin())->code())->toContain("Sampling::autoRuns('pullRequests', 'mergeGroups')")
        ->and($duration->written(ProjectRoot::origin())->line())->toBe('{"sampling":{"auto":"2h"}}')
        ->and($duration->php(ProjectRoot::origin())->code())->toContain("Sampling::auto('2h')")
        ->and($duration->php(ProjectRoot::origin())->code())->not->toContain('Sampling::autoRuns(')
        ->and($off->written(ProjectRoot::origin())->line())->toBe('{"sampling":{"auto":"off","autoRuns":["branches"]}}')
        ->and($off->php(ProjectRoot::origin())->code())->toContain('Sampling::autoOff()')
        ->and($off->php(ProjectRoot::origin())->code())->toContain("Sampling::autoRuns('branches')")
        ->and($runs->written(ProjectRoot::origin())->line())->toBe('{"sampling":{"autoRuns":["branches"]}}')
        ->and($runs->php(ProjectRoot::origin())->code())->toContain("Sampling::autoRuns('branches')")
        ->and($runs->php(ProjectRoot::origin())->code())->not->toContain('Sampling::auto(');
});

it('reads sampling.auto and sampling.autoRuns as a config writes them', function (): void {
    $sampling = Configs::settings([
        'runner' => 'pest',
        'sampling' => ['auto' => '90m', 'autoRuns' => ['mergeGroups', 'branches']],
    ])->reach()->sampling();

    expect($sampling->auto())->toEqual(AutoSample::aboveRunnerTime(Seconds::of(5400)))
        ->and([...$sampling->autoRuns()])->toBe([SampledRun::MergeGroups, SampledRun::Branches])
        ->and(Configs::settings(['runner' => 'pest', 'sampling' => ['auto' => 300]])->reach()->sampling()->auto())
        ->toEqual(AutoSample::aboveMutants(300));
});

it('refuses a threshold of no mutants, and a kind of run that never samples by itself', function (): void {
    expect(Configs::problems(Configs::layer(['sampling' => ['auto' => 0, 'autoRuns' => ['defaultBranch']]])))->toBe([
        'sampling.auto: expected "off", a count of mutants of at least 1, or a duration such as 90s, 15m, 1h30m or 7d, got 0',
        'sampling.autoRuns[0]: expected "pullRequests", "mergeGroups" or "branches", got "defaultBranch"',
    ]);
});
