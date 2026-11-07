<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\LearnedStatistic;
use NightWorksIO\MutationGate\Core\Cost\ShardsAtOnce;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\LimitHistory;
use NightWorksIO\MutationGate\Core\Runner\Multiple;

$killed = static fn(float ...$times): LimitHistory => LimitHistory::none()->afterKills(...array_map(Multiple::of(...), $times));

$ran = static fn(int ...$counts): LimitHistory => LimitHistory::none()->afterRuns(...array_map(ShardsAtOnce::of(...), $counts));

$times = static fn(LimitHistory $history): array => array_map(static fn(Multiple $kill): float => $kill->times(), [...$history->kills()]);

$counts = static fn(LimitHistory $history): array => array_map(static fn(ShardsAtOnce $run): int => $run->count(), [...$history->concurrency()]);

it('learns the multiple 99 in 100 kills took over their tests\' own time, kept between 2 and 10', function (array $kills, float $multiple) use ($killed): void {
    expect($killed(...array_values(array_filter($kills, is_float(...))))->multiple())->toEqual(Multiple::of($multiple));
})->with([
    'a hundred kills, one slow' => [fn(): array => [...array_fill(0, 99, 3.0), 50.0], 3.0],
    'two hundred kills, two slow' => [fn(): array => [...array_fill(0, 198, 4.0), 6.0, 50.0], 4.0],
    'two hundred kills, three slow' => [fn(): array => [...array_fill(0, 197, 4.0), 6.0, 7.0, 50.0], 6.0],
    'a single kill' => [fn(): array => [5.5], 5.5],
    'two kills, the slower first' => [fn(): array => [9.0, 4.0], 9.0],
    'sixty kills, one slow' => [fn(): array => [...array_fill(0, 59, 4.0), 7.0], 7.0],
    'kills quicker than twice their tests' => [fn(): array => [1.1, 1.4], 2.0],
    'kills slower than ten times their tests' => [fn(): array => [12.0, 40.0], 10.0],
]);

it('learns the pace of kills as the median of their multiples, unbounded', function (array $kills, float $pace) use ($killed): void {
    expect($killed(...array_values(array_filter($kills, is_float(...))))->pace())->toEqual(Multiple::of($pace));
})->with([
    'a single kill' => [[0.25], 0.25],
    'an odd count' => [[12.0, 0.5, 0.3], 0.5],
    'an even count' => [[0.75, 0.25, 0.5, 30.0], 0.625],
]);

it('learns no multiple and no pace from no kill, and no most shards from no run', function (): void {
    expect(LimitHistory::none()->multiple())->toBeInstanceOf(NotGiven::class)
        ->and(LimitHistory::none()->pace())->toBeInstanceOf(NotGiven::class)
        ->and(LimitHistory::none()->shards(LearnedStatistic::Max))->toBeInstanceOf(NotGiven::class);
});

it('learns the most shards recent runs ran at once, or their median', function () use ($ran): void {
    $runs = $ran(4, 9, 5, 6);

    expect($runs->shards(LearnedStatistic::Max))->toBe(9)
        ->and($runs->shards(LearnedStatistic::Median))->toBe(5)
        ->and($runs->afterRuns(ShardsAtOnce::of(7))->shards(LearnedStatistic::Median))->toBe(6);
});

it('learns no kill of no positive multiple and no run of no shard', function () use ($killed, $ran, $times, $counts): void {
    expect($times($killed(0.0, 2.5, -1.0)))->toBe([2.5])
        ->and($counts($ran(0, 1, 4)))->toBe([1, 4])
        ->and($killed(0.0)->killCount())->toBe(0)
        ->and($ran(0)->runCount())->toBe(0);
});

it('keeps only the newest 2,000 kills and the newest 10 runs', function () use ($killed, $ran, $times, $counts): void {
    $history = $killed(...array_fill(0, 2000, 9.0))->afterKills(Multiple::of(2.5))->afterRuns(...array_map(ShardsAtOnce::of(...), range(1, 12)));
    $kept = $times($history);

    expect($history->killCount())->toBe(2000)
        ->and($kept[1999])->toBe(2.5)
        ->and($kept[0])->toBe(9.0)
        ->and($history->runCount())->toBe(10)
        ->and($counts($history))->toBe(range(3, 12))
        ->and($counts($ran(...range(1, 11))))->toBe(range(2, 11));
});

it('reads another scope\'s kills or runs where this one learned none of them', function () use ($killed): void {
    $ours = $killed(2.5);
    $theirs = $killed(7.0)->afterRuns(ShardsAtOnce::of(4));

    expect($ours->and($theirs))->toEqual($ours->afterRuns(ShardsAtOnce::of(4)))
        ->and(LimitHistory::none()->and($theirs))->toEqual($theirs)
        ->and($theirs->and($ours))->toEqual($theirs);
});
