<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Config\ShardTail;
use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Plan\LeftForks;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\PlanLearned;
use NightWorksIO\MutationGate\Core\Plan\SecondWave;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('cuts the shares of jobs that stop to their stop, folding them into at most the pool\'s shares that run their own tail where they outnumber it, never a share past its job\'s limit', function (JobTail $tail, Shards $settings, int $shares, bool $tails): void {
    $left = [];

    foreach ([1, 2, 3, 4] as $at) {
        $file = sprintf('src/S%d.php', $at);
        $left[] = [LeftMutant::of(MutantId::hash(Path::of($file), 'Minus', 'a', $at), 'Minus', Path::of($file)), [sprintf('T%d', $at)], LeftForks::atKiller(0.0)];
    }

    $cut = SecondWave::cut(
        Digest::sha256Of('plan'),
        [1 => $left],
        ['T1' => 50.0, 'T2' => 50.0, 'T3' => 50.0, 'T4' => 50.0],
        [1 => Seconds::of(10.0)],
        PlanLearned::none()->tailing($tail),
        $settings,
        StreamWall::of(1),
    )->ofShard(ShardId::of(1));

    expect($cut)->toHaveCount($shares)
        ->and(array_values(array_unique(array_map(static fn(LeftoverShare $share): bool => $share->runsItsTail(), $cut))))->toBe([$tails]);
})->with([
    'stopping, within the pool' => [fn(): JobTail => JobTail::of(Seconds::of(3600.0), 4), fn(): Shards => Shards::of(jobLimit: Seconds::of(70.0), tail: ShardTail::Auto), 4, false],
    'stopping, past the pool, a limit no longer than the stop: as many shares as the limit holds, in waves' => [
        fn(): JobTail => JobTail::of(Seconds::of(3600.0), 2),
        fn(): Shards => Shards::of(jobLimit: Seconds::of(70.0), tail: ShardTail::Auto),
        4,
        true,
    ],
    'stopping, past the pool under an hour\'s limit: folded into the pool\'s shares' => [
        fn(): JobTail => JobTail::of(Seconds::of(3600.0), 2),
        fn(): Shards => Shards::of(target: Seconds::of(130.0), setup: Seconds::none(), tail: ShardTail::Auto),
        1,
        true,
    ],
    'stopping, shards.tail stop' => [fn(): JobTail => JobTail::of(Seconds::of(3600.0), 2), fn(): Shards => Shards::of(jobLimit: Seconds::of(70.0), tail: ShardTail::Stop), 4, false],
    'running within a limit that holds one mutant: one share each, past the pool, in waves' => [
        fn(): JobTail => JobTail::of(Seconds::of(3600.0), 2)->running(),
        fn(): Shards => Shards::of(jobLimit: Seconds::of(70.0), tail: ShardTail::Auto),
        4,
        true,
    ],
    'running within an hour: one share' => [fn(): JobTail => JobTail::of(Seconds::of(3600.0), 2)->running(), fn(): Shards => Shards::of(tail: ShardTail::Auto), 1, true],
]);

it('prices each shard\'s shares at the second pass its own job measured, else at the run\'s median, else at the factor the plan learned, 1.0 where it learned none', function (array $factors, StreamWall $wall, array $second): void {
    $jobs = [];

    foreach (array_values($factors) as $at => $factor) {
        $jobs[$at + 1] = JobTimes::of('', NotGiven::value(), NotGiven::value(), is_float($factor) ? $factor : NotGiven::value());
    }

    $left = static function (int $shard): array {
        $file = sprintf('src/S%d.php', $shard);

        return [[LeftMutant::of(MutantId::hash(Path::of($file), 'Minus', 'a', $shard), 'Minus', Path::of($file)), ['T'], LeftForks::atEveryTest(4800.0)]];
    };
    $cut = SecondWave::cut(
        Digest::sha256Of('plan'),
        [1 => $left(1), 2 => $left(2), 3 => $left(3)],
        ['T' => 4800.0],
        [1 => Seconds::of(10.0), 2 => Seconds::of(10.0), 3 => Seconds::of(10.0)],
        PlanLearned::none()->tailing(JobTail::of(Seconds::of(21600.0), 20)->running()),
        Shards::of(tail: ShardTail::Auto),
        $wall,
        $jobs,
    );
    $priced = [];

    foreach ([1, 2, 3] as $shard) {
        foreach ($cut->ofShard(ShardId::of($shard)) as $share) {
            $priced[$shard] = round($share->predicted()->second()->seconds(), 3);
        }
    }

    expect($priced)->toBe($second);
})->with([
    'each shard measured its own' => [
        [0.005, 0.01, 0.02],
        fn(): StreamWall => StreamWall::of(1),
        [1 => 24.0, 2 => 48.0, 3 => 96.0],
    ],
    'one shard measured none: the run\'s median' => [
        [0.005, 0.02, null],
        fn(): StreamWall => StreamWall::of(1),
        [1 => 24.0, 2 => 96.0, 3 => 60.0],
    ],
    'no job measured one: the factor the plan learned' => [[], fn(): StreamWall => StreamWall::of(1, 0.5), [1 => 2400.0, 2 => 2400.0, 3 => 2400.0]],
    'nothing measured or learned: 1.0' => [[], fn(): StreamWall => StreamWall::of(1), [1 => 4800.0, 2 => 4800.0, 3 => 4800.0]],
]);

it('cuts the kills a shard left waiting for their control, many to a test, into one share where its jobs run their own tail, and into many where they stop at their target', function (): void {
    $left = [];

    foreach (range(1, 247) as $at) {
        $file = sprintf('bootstrap/Composition/Part%d.php', $at % 12);
        $left[] = [LeftMutant::of(MutantId::hash(Path::of($file), 'Minus', 'a', $at), sprintf('n%d', $at), Path::of($file)), [sprintf('tests/Contract/T%dTest.php', $at % 53)], LeftForks::atKiller(2.0)];
    }

    $timed = [];

    foreach (range(0, 52) as $test) {
        $timed[sprintf('tests/Contract/T%dTest.php', $test)] = 10.0;
    }

    $cut = static fn(JobTail $tail, ShardTail $setting): array => SecondWave::cut(
        Digest::sha256Of('plan'),
        [57 => $left],
        $timed,
        [57 => Seconds::of(30.0)],
        PlanLearned::none()->tailing($tail),
        Shards::of(tail: $setting),
        StreamWall::of(1),
    )->ofShard(ShardId::of(57));
    $running = $cut(JobTail::of(Seconds::minutes(60), 20)->running(), ShardTail::Auto);
    $stopping = $cut(JobTail::of(Seconds::minutes(60), 20), ShardTail::Stop);

    expect($running)->toHaveCount(1)
        ->and(count([...$running[0]->mutants()]))->toBe(247)
        ->and(count($stopping))->toBeGreaterThan(1)
        ->and(array_sum(array_map(static fn(LeftoverShare $share): int => count([...$share->mutants()]), $stopping)))->toBe(247);
});
