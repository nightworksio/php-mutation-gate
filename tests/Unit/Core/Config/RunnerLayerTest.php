<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\RunnerLayer;
use NightWorksIO\MutationGate\Core\Config\RunnerPacing;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\PathRuleMode;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;

$layer = static fn(Choice|Absent $runner): RunnerLayer => RunnerLayer::of(
    $runner,
    Withheld::of('A_TOKEN', 'B_TOKEN'),
    MemoryCap::of(512, MemoryUnit::Megabytes),
    Workers::Fresh,
    PathRuleMode::Partitioned,
    RunnerPacing::of(recycleAfter: 50),
);

it('writes every setting it holds on the runner it chooses, in the order the builder calls them', function () use ($layer): void {
    expect($layer(Choice::of('pest', Options::none()))->php()->code())->toBe(
        "\n    ->runner(Runner::pest()->withholding(Withheld::of('A_TOKEN', 'B_TOKEN'))->cappedAt(MemoryCap::of(512, MemoryUnit::Megabytes))"
        . '->inWorkers(Workers::Fresh)->applyingPathRules(PathRuleMode::Partitioned)->paced(Pace::of(recycleAfter: 50)))',
    );
});

it('writes every setting it holds on the gate where it chooses no runner', function () use ($layer): void {
    expect($layer(Absent::setting())->php()->code())->toBe(
        "\n    ->withholding(Withheld::of('A_TOKEN', 'B_TOKEN'))"
        . "\n    ->cappedAt(MemoryCap::of(512, MemoryUnit::Megabytes))"
        . "\n    ->inWorkers(Workers::Fresh)"
        . "\n    ->with(\n        PathRule::applying(PathRuleMode::Partitioned),\n        Pace::of(recycleAfter: 50),\n    )",
    );
});

it('writes a memory cap of none as the builder names it', function (): void {
    $layer = RunnerLayer::of(Absent::setting(), Absent::setting(), MemoryCap::none(), Absent::setting(), Absent::setting(), RunnerPacing::of());

    expect($layer->php()->code())->toBe("\n    ->cappedAt(MemoryCap::none())");
});

it('writes a runner it chooses alone as the builder method of its name', function (): void {
    $layer = RunnerLayer::of(Choice::of('pest', Options::none()), Absent::setting(), Absent::setting(), Absent::setting(), Absent::setting(), RunnerPacing::of());

    expect($layer->php()->code())->toBe("\n    ->runner(Runner::pest())");
});

it('withholds what either layer withholds, each once, and writes no withhold where neither says one', function (): void {
    $withholding = static fn(string ...$names): RunnerLayer => RunnerLayer::of(Absent::setting(), Withheld::of(...$names), Absent::setting(), Absent::setting(), Absent::setting(), RunnerPacing::of());
    $silent = RunnerLayer::of(Absent::setting(), Absent::setting(), Absent::setting(), Absent::setting(), Absent::setting(), RunnerPacing::of());

    expect([
        [...$withholding('A_TOKEN')->over($silent)->withhold()],
        [...$silent->over($withholding('A_TOKEN', 'A_TOKEN'))->withhold()],
        [...$withholding('A_TOKEN')->over($withholding('A_TOKEN', 'B_TOKEN'))->withhold()],
    ])->toBe([['A_TOKEN'], ['A_TOKEN'], ['A_TOKEN', 'B_TOKEN']])
        ->and($silent->over($silent)->written(Json::object())->line())->toBe('{}');
});
