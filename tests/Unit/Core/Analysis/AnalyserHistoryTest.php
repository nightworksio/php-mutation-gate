<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\RejectionRate;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$plus = Mutation::of('Plus', MutatorFamily::Arithmetic, "-+\n+-");
$minus = Mutation::of('Minus', MutatorFamily::Arithmetic, "--\n++");

it('learns each check: its mutator\'s rate and its time', function () use ($plus, $minus): void {
    $history = AnalyserHistory::of('phpstan')
        ->rejected($plus, Seconds::of(1.0))
        ->passed($plus, Seconds::of(2.0))
        ->passed($minus, Seconds::of(3.0));

    expect($history->analyser())->toBe('phpstan')
        ->and($history->rateOf($plus))->toEqual(RejectionRate::of('Plus', 2, 1))
        ->and($history->rateOf($minus))->toEqual(RejectionRate::of('Minus', 1, 0))
        ->and($history->time())->toEqual(CheckTime::of(3, Seconds::of(6.0)))
        ->and([...$history])->toEqual([RejectionRate::of('Plus', 2, 1), RejectionRate::of('Minus', 1, 0)]);
});

it('knows nothing of a mutator it never checked', function () use ($plus): void {
    expect(AnalyserHistory::of('mago')->rateOf($plus))->toEqual(RejectionRate::unchecked('Plus'))
        ->and(AnalyserHistory::of('mago')->time())->toEqual(CheckTime::none())
        ->and([...AnalyserHistory::of('mago')])->toBe([]);
});

it('reads its own rates and time before another ledger\'s, and takes the other\'s where it has none', function () use ($plus, $minus): void {
    $own = AnalyserHistory::of('phpstan')->withRate(RejectionRate::of('Plus', 10, 2));
    $other = AnalyserHistory::of('phpstan')
        ->withRate(RejectionRate::of('Plus', 60, 30))
        ->withRate(RejectionRate::of('Minus', 5, 1))
        ->withTime(CheckTime::of(65, Seconds::of(13.0)));
    $timed = $own->withTime(CheckTime::of(10, Seconds::of(1.0)));

    expect($own->and($other)->rateOf($plus))->toEqual(RejectionRate::of('Plus', 10, 2))
        ->and($own->and($other)->rateOf($minus))->toEqual(RejectionRate::of('Minus', 5, 1))
        ->and($own->and($other)->time())->toEqual(CheckTime::of(65, Seconds::of(13.0)))
        ->and($timed->and($other)->time())->toEqual(CheckTime::of(10, Seconds::of(1.0)));
});

it('keeps one rate of a mutator whose name reads as a number, this history\'s first', function (): void {
    $twelve = Mutation::of('12', MutatorFamily::None, '');
    $history = AnalyserHistory::of('7')->withRate(RejectionRate::of('12', 1, 0))->withRate(RejectionRate::of('12', 2, 1));
    $read = $history->and(AnalyserHistory::of('7')->withRate(RejectionRate::of('12', 9, 9)));

    expect([...$history])->toEqual([RejectionRate::of('12', 2, 1)])
        ->and([...$read])->toEqual([RejectionRate::of('12', 2, 1)])
        ->and($read->rateOf($twelve))->toEqual(RejectionRate::of('12', 2, 1));
});

it('learns a check after the tests by its time alone', function () use ($plus): void {
    $history = AnalyserHistory::of('mago')->checked(Seconds::of(0.25))->checked(Seconds::of(0.75));

    expect($history->time())->toEqual(CheckTime::of(2, Seconds::of(1.0)))
        ->and($history->rateOf($plus))->toEqual(RejectionRate::unchecked('Plus'))
        ->and([...$history])->toBe([]);
});

it('adds what a run learned to it: each mutator\'s checks and rejections, and the checks\' time', function () use ($plus, $minus): void {
    $held = AnalyserHistory::of('phpstan')
        ->withRate(RejectionRate::of('Plus', 10, 2))
        ->withTime(CheckTime::of(10, Seconds::of(5.0)));
    $run = AnalyserHistory::of('phpstan')
        ->withRate(RejectionRate::of('Plus', 2, 1))
        ->withRate(RejectionRate::of('Minus', 1, 0))
        ->withTime(CheckTime::of(3, Seconds::of(1.0)));
    $added = $held->plus($run);

    expect($added->analyser())->toBe('phpstan')
        ->and($added->rateOf($plus))->toEqual(RejectionRate::of('Plus', 12, 3))
        ->and($added->rateOf($minus))->toEqual(RejectionRate::of('Minus', 1, 0))
        ->and($added->time())->toEqual(CheckTime::of(13, Seconds::of(6.0)));
});
