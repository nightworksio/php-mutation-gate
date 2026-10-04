<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;

it('measures no peak and records first killers, by default', function (): void {
    expect(Briefing::standard()->peak())->toEqual(NotGiven::value())
        ->and(Briefing::standard()->matrix())->toBe(MatrixKind::FirstKiller);
});

it('takes each of what it tells on its own, leaving the other as it was', function (): void {
    $peak = MemoryCap::of(300, MemoryUnit::Megabytes);
    $weighed = Briefing::standard()->weighing($peak);
    $recording = Briefing::standard()->recording(MatrixKind::Full);

    expect($weighed->peak())->toBe($peak)
        ->and($weighed->matrix())->toBe(MatrixKind::FirstKiller)
        ->and($recording->matrix())->toBe(MatrixKind::Full)
        ->and($recording->peak())->toEqual(NotGiven::value())
        ->and($weighed->recording(MatrixKind::Full)->peak())->toBe($peak);
});
