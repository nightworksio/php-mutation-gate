<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;

$makePlan = static fn(): Plan => Plan::of(
    Revision::ref('5eeca8f'),
    Digest::sha256Of('base'),
    Keys::none(),
    Shards::of(Shard::empty(ShardId::of(1)), Shard::empty(ShardId::of(2)), Shard::empty(ShardId::of(3))),
);

it('names the shard a CI variable names, adding one where it counts from 0', function (Variables $set, int $shard) use (
    $makePlan,
): void {
    $plan = $makePlan();

    expect(WhichShard::in($set, $plan))->toEqual(ShardId::of($shard));
})->with([
    'a matrix job' => [fn(): Variables => Variables::of(['SHARD' => '2']), 2],
    'a GitLab parallel job' => [fn(): Variables => Variables::of(['CI_NODE_INDEX' => '2', 'CI_NODE_TOTAL' => '3']), 2],
    'a Buildkite parallel job' => [fn(): Variables => Variables::of(['BUILDKITE_PARALLEL_JOB' => '1', 'BUILDKITE_PARALLEL_JOB_COUNT' => '3']), 2],
    'a CircleCI node' => [fn(): Variables => Variables::of(['CIRCLE_NODE_INDEX' => '0', 'CIRCLE_NODE_TOTAL' => '3']), 1],
    'a Bitbucket parallel step' => [fn(): Variables => Variables::of(['BITBUCKET_PARALLEL_STEP' => '0', 'BITBUCKET_PARALLEL_STEP_COUNT' => '3']), 1],
    'a parallel job that does not say how many there are' => [fn(): Variables => Variables::of(['CI_NODE_INDEX' => '3']), 3],
]);

it('reads the first variable the CI set', function (Variables $set, int $shard) use ($makePlan): void {
    $plan = $makePlan();

    expect(WhichShard::in($set, $plan))->toEqual(ShardId::of($shard));
})->with([
    'a matrix job before a parallel one' => [fn(): Variables => Variables::of(['CI_NODE_INDEX' => '1', 'SHARD' => '3']), 3],
    'GitLab before Buildkite' => [fn(): Variables => Variables::of(['BUILDKITE_PARALLEL_JOB' => '0', 'CI_NODE_INDEX' => '3']), 3],
    'Buildkite before CircleCI' => [fn(): Variables => Variables::of(['CIRCLE_NODE_INDEX' => '0', 'BUILDKITE_PARALLEL_JOB' => '2']), 3],
    'CircleCI before Bitbucket' => [fn(): Variables => Variables::of(['BITBUCKET_PARALLEL_STEP' => '0', 'CIRCLE_NODE_INDEX' => '2']), 3],
    'a variable set to nothing skipped' => [fn(): Variables => Variables::of(['SHARD' => '', 'CIRCLE_NODE_INDEX' => '1']), 2],
    'a count of another variable ignored' => [fn(): Variables => Variables::of(['SHARD' => '1', 'CI_NODE_TOTAL' => '9']), 1],
]);

it('cannot judge a job count that is not the plan\'s count of shards', function (string $index, string $total) use ($makePlan): void {
    $plan = $makePlan();

    $set = Variables::of([$index => '1', $total => '4']);

    expect(WhichShard::in($set, $plan))->toEqual(CannotJudge::because(
        sprintf('%s is 4, and the plan holds 3 shards. Plan with --shards=4, so each job has a shard.', $total),
    ));
})->with([
    'GitLab' => ['CI_NODE_INDEX', 'CI_NODE_TOTAL'],
    'Buildkite' => ['BUILDKITE_PARALLEL_JOB', 'BUILDKITE_PARALLEL_JOB_COUNT'],
    'CircleCI' => ['CIRCLE_NODE_INDEX', 'CIRCLE_NODE_TOTAL'],
    'Bitbucket' => ['BITBUCKET_PARALLEL_STEP', 'BITBUCKET_PARALLEL_STEP_COUNT'],
]);

it('cannot judge a job named by something other than a number', function () use ($makePlan): void {
    $plan = $makePlan();

    expect(WhichShard::in(Variables::of(['SHARD' => 'two']), $plan))
        ->toEqual(CannotJudge::because('SHARD is "two", which is not a job number.'))
        ->and(WhichShard::in(Variables::of(['SHARD' => '-1']), $plan))
        ->toEqual(CannotJudge::because('SHARD is "-1", which is not a job number.'));
});

it('cannot judge a job the plan has no shard for', function () use ($makePlan): void {
    $plan = $makePlan();

    expect(WhichShard::in(Variables::of(['SHARD' => '4']), $plan))->toEqual(CannotJudge::because(
        'The plan has no shard 4. It holds 3 shards, so this job was not planned from it.',
    ));
});

it('cannot judge a job no variable names', function () use ($makePlan): void {
    $plan = $makePlan();

    expect(WhichShard::in(Variables::of([]), $plan))->toEqual(CannotJudge::because(
        'No shard is named. Pass --shard=<id>, or run under a CI that sets one of SHARD, CI_NODE_INDEX, '
            . 'BUILDKITE_PARALLEL_JOB, CIRCLE_NODE_INDEX, BITBUCKET_PARALLEL_STEP.',
    ));
});
