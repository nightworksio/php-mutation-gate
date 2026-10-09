<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Report\SavingsText;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\PruningCases;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$timings = static fn(RunTime $spent): RunTimings => RunTimings::of('github:1/1', $spent);
$savings = static fn(): Savings => Savings::of(Seconds::of(6_060.0), Percentage::of(Floor::of(94.99)), Seconds::of(4_800.0), Seconds::of(420.0));

it('says what a sharded run took and saved, in the one headline', function () use ($timings, $savings): void {
    expect(SavingsText::headline($timings(RunTime::measured(Seconds::of(360.0), Seconds::of(840.0))), $savings()->sharded(Seconds::of(2_280.0), Seconds::of(180.0))))
        ->toBe('Judged in 6m wall, 14m runner time. A full one-job run: 1h 41m (94% measured). Reach saved 1h 20m, proofs 7m; sharding cut the wait by 38m and cost 3m of setup.');
});

it('leaves sharding out of an unsharded run, and says an estimated runner time is one', function () use ($timings, $savings): void {
    expect(SavingsText::headline($timings(RunTime::estimated(Seconds::of(360.0), Seconds::of(840.0))), $savings()))
        ->toBe('Judged in 6m wall, 14m runner time (estimated). A full one-job run: 1h 41m (94% measured). Reach saved 1h 20m, proofs 7m.');
});

it('says a run with no history saved nothing it can show', function () use ($timings): void {
    expect(SavingsText::headline($timings(RunTime::measured(Seconds::of(45.0), Seconds::of(45.0))), NoHistory::yet()))
        ->toBe('Judged in 45s wall, 45s runner time. No history yet, so nothing saved is shown.');
});

it('gives a verdict\'s headline, what the default branch saved lately under it, and nothing for an untimed run', function (): void {
    $verdict = Verdicts::named('accounted');
    $headline = 'Judged in 6m wall, 14m runner time. A full one-job run: 1h 41m (94% measured). Reach saved 1h 20m, proofs 7m; sharding cut the wait by 38m and cost 3m of setup.';

    expect(SavingsText::of($verdict, NoHistory::yet()))->toBe($headline)
        ->and(SavingsText::of($verdict, Seconds::of(147_600.0)))->toBe(sprintf("%s\nIn the last 30 days the gate saved 41h of runner time.", $headline))
        ->and(SavingsText::of(Verdicts::failing(), Seconds::of(60.0)))->toBe('')
        ->and(SavingsText::DAYS)->toBe(30);
});

it('says what pruning saved in the headline, and under it what pruning left out, though the run was untimed', function (): void {
    $account = Verdicts::account()->withPruning(PruningCases::account());
    $headline = 'Judged in 6m wall, 14m runner time. A full one-job run: 1h 41m (94% measured). Reach saved 1h 20m, proofs 7m, pruning 2m; sharding cut the wait by 38m and cost 3m of setup.';
    $pruned = 'Pruned: 2 mutators on 1 unit; 3 mutants carried from runs of the last 7 days.';

    expect(SavingsText::of(Verdicts::failing()->withAccount($account), Seconds::of(147_600.0)))
        ->toBe(sprintf("%s\nIn the last 30 days the gate saved 41h of runner time.\n%s", $headline, $pruned))
        ->and(SavingsText::of(Verdicts::failing()->withAccount(RunAccount::none()->withPruning(PruningCases::account())), Seconds::of(60.0)))
        ->toBe($pruned);
});
