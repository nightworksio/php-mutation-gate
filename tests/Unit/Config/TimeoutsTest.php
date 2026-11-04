<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\Config\LearnedStatistic;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;

it('writes each learned limit it sets, and a written multiple', function (): void {
    expect(Configs::written(Gate::configure()->with(
        Timeouts::learnedSeconds(),
        Timeouts::learnedMultiple(),
        Shards::learnedMax(),
        Shards::learnedFromMedian(),
    )))->toBe([
        'timeouts' => ['seconds' => 'learned', 'multiple' => 'learned'],
        'shards' => ['max' => 'learned', 'learnedFrom' => 'median'],
    ])
        ->and(Configs::written(Gate::configure()->with(Timeouts::multiple(4.5), Shards::learnedFromMax())))
        ->toBe(['timeouts' => ['multiple' => 4.5], 'shards' => ['learnedFrom' => 'max']]);
});

it('reads learned limits into bounds that learn them, and writes them back as the builder calls them', function (): void {
    $settings = Configs::settings([
        'runner' => 'pest',
        'timeouts' => ['seconds' => 'learned', 'multiple' => 'learned'],
        'shards' => ['max' => 'learned'],
    ]);
    $bounds = $settings->triage()->bounds();

    expect([$bounds->learnsFloor(), $bounds->learnsMultiple(), $bounds->floor(), $bounds->multiple()])
        ->toEqual([true, true, Seconds::of(10.0), Multiple::standard()])
        ->and($settings->shards()->max())->toBe(20)
        ->and($settings->shards()->learnedFrom())->toBe(LearnedStatistic::Max)
        ->and(Decoded::at($settings->effective()->written(ProjectRoot::origin())->line(), 'timeouts', 'multiple'))->toBe('learned')
        ->and(Decoded::at($settings->effective()->written(ProjectRoot::origin())->line(), 'shards', 'max'))->toBe('learned')
        ->and($settings->effective()->php(ProjectRoot::origin())->code())
        ->toContain('Timeouts::learnedSeconds()', 'Timeouts::learnedMultiple()', 'Shards::learnedMax()');
});

it('learns no limit a config writes a number for, and takes no statistic for a written most', function (): void {
    $settings = Configs::settings(['runner' => 'pest', 'timeouts' => ['seconds' => 7, 'multiple' => 4], 'shards' => ['max' => 8]]);
    $bounds = $settings->triage()->bounds();

    expect([$bounds->learnsFloor(), $bounds->learnsMultiple(), $bounds->floor(), $bounds->multiple()])
        ->toEqual([false, false, Seconds::of(7.0), Multiple::of(4.0)])
        ->and($settings->shards()->max())->toBe(8)
        ->and($settings->shards()->learnedFrom())->toBeInstanceOf(NotGiven::class);
});

it('refuses a multiple under one and a word other than learned', function (array $config, string $problem): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', ...$config])))->toContain($problem);
})->with([
    'a multiple under one' => [['timeouts' => ['multiple' => 0.5]], 'timeouts.multiple: expected a number of at least 1, or "learned", got 0.5'],
    'another word' => [['shards' => ['max' => 'many']], 'shards.max: expected an integer of at least 1, or "learned", got "many"'],
    'a statistic it does not know' => [['shards' => ['learnedFrom' => 'mean']], 'shards.learnedFrom: expected "max" or "median", got "mean"'],
]);
