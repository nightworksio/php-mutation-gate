<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Config\ShardTail;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Plan\LeftForks;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\PlanLearned;
use NightWorksIO\MutationGate\Core\Plan\SecondWave;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('cuts the shares of jobs that stop to their stop, folding them into at most the pool\'s shares that run their own tail where they outnumber it', function (JobTail $tail, ShardTail $setting, int $shares, bool $tails): void {
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
        Shards::of(jobLimit: Seconds::of(70.0), tail: $setting),
        StreamWall::of(1),
    )->ofShard(ShardId::of(1));

    expect($cut)->toHaveCount($shares)
        ->and(array_values(array_unique(array_map(static fn(LeftoverShare $share): bool => $share->runsItsTail(), $cut))))->toBe([$tails]);
})->with([
    'stopping, within the pool' => [fn(): JobTail => JobTail::of(Seconds::of(3600.0), 4), ShardTail::Auto, 4, false],
    'stopping, past the pool: folded' => [fn(): JobTail => JobTail::of(Seconds::of(3600.0), 2), ShardTail::Auto, 2, true],
    'stopping, shards.tail stop' => [fn(): JobTail => JobTail::of(Seconds::of(3600.0), 2), ShardTail::Stop, 4, false],
    'running: at most the pool' => [fn(): JobTail => JobTail::of(Seconds::of(3600.0), 2)->running(), ShardTail::Auto, 2, true],
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
