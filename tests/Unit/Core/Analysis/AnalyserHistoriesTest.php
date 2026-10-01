<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\RejectionRate;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$phpstan = AnalyserIdentity::of('phpstan', '2.1.30', Digest::of(str_repeat('a', 64)));
$plus = Mutation::of('Plus', MutatorFamily::Arithmetic, "-+\n+-");

it('holds one history per analyser, the newest of two', function () use ($phpstan): void {
    $first = AnalyserHistory::of('phpstan')->withTime(CheckTime::of(1, Seconds::of(1.0)));
    $second = AnalyserHistory::of('phpstan')->withTime(CheckTime::of(2, Seconds::of(3.0)));
    $histories = AnalyserHistories::none()->with($first)->with(AnalyserHistory::of('mago'))->with($second);

    expect($histories->of($phpstan))->toBe($second)
        ->and(array_map(static fn(AnalyserHistory $history): string => $history->analyser(), [...$histories]))->toBe(['phpstan', 'mago']);
});

it('gives an analyser it learned nothing of a history with nothing in it', function () use ($phpstan): void {
    expect(AnalyserHistories::none()->of($phpstan))->toEqual(AnalyserHistory::of('phpstan'))
        ->and([...AnalyserHistories::none()])->toBe([]);
});

it('reads its own histories before another ledger\'s, merging an analyser both know', function () use ($phpstan, $plus): void {
    $own = AnalyserHistories::none()->with(AnalyserHistory::of('phpstan')->withRate(RejectionRate::of('Plus', 3, 1)));
    $other = AnalyserHistories::none()
        ->with(AnalyserHistory::of('mago')->withRate(RejectionRate::of('Plus', 9, 9)))
        ->with(AnalyserHistory::of('phpstan')->withRate(RejectionRate::of('Plus', 60, 0))->withTime(CheckTime::of(60, Seconds::of(30.0))));
    $read = $own->and($other);
    $mago = AnalyserIdentity::of('mago', '1.0.0', Digest::of(str_repeat('b', 64)));

    expect($read->of($phpstan)->rateOf($plus))->toEqual(RejectionRate::of('Plus', 3, 1))
        ->and($read->of($phpstan)->time())->toEqual(CheckTime::of(60, Seconds::of(30.0)))
        ->and($read->of($mago)->rateOf($plus))->toEqual(RejectionRate::of('Plus', 9, 9))
        ->and($own->and(AnalyserHistories::none()))->toEqual($own);
});

it('keeps one history of an analyser whose name reads as a number, the newest', function (): void {
    $seven = AnalyserIdentity::of('7', '1.0.0', Digest::of(str_repeat('c', 64)));
    $newest = AnalyserHistory::of('7')->withTime(CheckTime::of(2, Seconds::of(1.0)));
    $histories = AnalyserHistories::none()->with(AnalyserHistory::of('7'))->with($newest);

    expect([...$histories])->toEqual([$newest])
        ->and($histories->of($seven))->toBe($newest)
        ->and([...$histories->and(AnalyserHistories::none()->with(AnalyserHistory::of('7')))])->toEqual([$newest]);
});

it('adds what a run learned of each analyser to what it holds, and takes one it held nothing of whole', function () use ($phpstan): void {
    $held = AnalyserHistories::none()->with(AnalyserHistory::of('phpstan')->withTime(CheckTime::of(4, Seconds::of(2.0))));
    $run = AnalyserHistories::none()
        ->with(AnalyserHistory::of('phpstan')->withTime(CheckTime::of(1, Seconds::of(1.0))))
        ->with(AnalyserHistory::of('7')->withTime(CheckTime::of(2, Seconds::of(1.0))));
    $added = $held->plus($run);
    $seven = AnalyserIdentity::of('7', '1.0.0', Digest::of(str_repeat('c', 64)));

    expect($added->of($phpstan)->time())->toEqual(CheckTime::of(5, Seconds::of(3.0)))
        ->and($added->of($seven)->time())->toEqual(CheckTime::of(2, Seconds::of(1.0)))
        ->and(array_map(static fn(AnalyserHistory $history): string => $history->analyser(), [...$added]))->toBe(['phpstan', '7']);
});
