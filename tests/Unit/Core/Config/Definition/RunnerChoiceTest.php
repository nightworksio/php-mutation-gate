<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Definition\RunnerChoice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;

it('expects what any adapter expects', function (): void {
    expect(RunnerChoice::choosing(Builtins::runners(ProjectRoot::origin()))->expected())->toBe(Adapter::EXPECTED);
});

it('keeps the effect of each option its adapters take, and lets what a runner withholds, caps and runs on affect results', function (): void {
    $runners = Builtins::treeSources(ProjectRoot::origin());

    expect($runners->effects())->not->toBe([])
        ->and(RunnerChoice::choosing($runners)->effects())->toBe([
            ...$runners->effects(),
            '.withhold' => Effect::AffectsResults,
            '.memory' => Effect::AffectsResults,
            '.workers' => Effect::AffectsResults,
        ]);
});
