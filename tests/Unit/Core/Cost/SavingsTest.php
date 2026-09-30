<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Cost\Untimed;
use NightWorksIO\MutationGate\Core\Report\NoTrend;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('holds what an unsharded run saved, and a sharded one\'s wait and setup', function (): void {
    $measured = Percentage::of(Floor::of(94.5));
    $savings = Savings::of(Seconds::of(6_060.0), $measured, Seconds::of(4_800.0), Seconds::of(420.0));
    $sharded = $savings->sharded(Seconds::of(2_280.0), Seconds::of(180.0));

    expect([$savings->fullRun(), $savings->measured(), $savings->reach(), $savings->proofs()])
        ->toEqual([Seconds::of(6_060.0), $measured, Seconds::of(4_800.0), Seconds::of(420.0)])
        ->and([$savings->isSharded(), $savings->waitSaved(), $savings->shardingSetup()])->toEqual([false, Seconds::of(0.0), Seconds::of(0.0)])
        ->and([$sharded->isSharded(), $sharded->waitSaved(), $sharded->shardingSetup()])->toEqual([true, Seconds::of(2_280.0), Seconds::of(180.0)])
        ->and($measured->wholePercent())->toBe(94);
});

it('knows no timings, cost, history or trend until the flows give them', function (): void {
    $account = RunAccount::none();
    $trend = Trend::none();

    expect($account->timings())->toEqual(Untimed::run())
        ->and($account->cost())->toEqual(Untimed::run())
        ->and($account->savings())->toEqual(NoHistory::yet())
        ->and($account->previous())->toEqual(NoTrend::offTheDefaultBranch())
        ->and($account->after($trend)->previous())->toBe($trend)
        ->and(Verdicts::failing()->account())->toEqual(RunAccount::none());
});

it('counts what the default branch saved lately, this run among them, and nothing off it', function (): void {
    $since = Moment::at('2026-08-31T12:00:00Z');
    $timed = RunTimings::of('github:1/1', RunTime::measured(Seconds::of(60.0), Seconds::of(100.0)));
    $savings = Savings::of(Seconds::of(400.0), Percentage::of(Floor::of(100)), Seconds::of(300.0), Seconds::of(0.0));
    $trend = Trend::decode('{"format": 1, "runs": [
        {"commit": "a", "time": "2026-08-01T00:00:00Z", "trees": {}, "runnerSeconds": 10, "fullRunSeconds": 1000},
        {"commit": "b", "time": "2026-09-10T00:00:00Z", "trees": {}, "runnerSeconds": 100, "fullRunSeconds": 700},
        {"commit": "c", "time": "2026-09-11T00:00:00Z", "trees": {}, "runnerSeconds": 900, "fullRunSeconds": 700},
        {"commit": "d", "time": "2026-09-12T00:00:00Z", "trees": {}}
    ]}');
    $own = RunAccount::none()->withTimings($timed)->withSavings($savings);

    expect($own->savedSince($since))->toEqual(NoHistory::yet())
        ->and($own->after(Trend::none())->savedSince($since))->toEqual(Seconds::of(300.0))
        ->and($own->after($trend)->savedSince($since))->toEqual(Seconds::of(900.0))
        ->and(RunAccount::none()->after($trend)->savedSince($since))->toEqual(Seconds::of(600.0))
        ->and(RunAccount::none()->after(Trend::none())->savedSince($since))->toEqual(NoHistory::yet())
        ->and(RunAccount::none()->withTimings($timed)->after(Trend::none())->savedSince($since))->toEqual(NoHistory::yet());
});
