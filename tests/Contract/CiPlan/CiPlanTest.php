<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

// What every CI plan answers for a job started as shard 2: that it is shard 2
// of a plan that holds it, and cannot judge a plan that does not; that it
// hands any plan to its CI; and what it can say of the run. One line per
// implementation.

afterEach(function (): void {
    Scratch::sweep();
});

$job = static fn(): Variables => Variables::of([
    'SHARD' => '2',
    'GITHUB_OUTPUT' => sprintf('%s/output', Scratch::directory()),
    'CI_JOB_NAME' => 'mutation-plan',
]);

$plans = [
    'the fake' => fn(): CiPlan => new CiPlanFake(ShardId::of(2), RunOn::branch('main', RunOn::branchNamed('main'))),
    'GitHub Actions' => fn(): CiPlan => GitHubPlan::in($job()),
    'GitLab CI' => fn(): CiPlan => GitLabPlan::writing(
        sprintf('%s/pipeline.yml', Scratch::directory()),
        'ci/gate.yml',
        $job(),
    ),
    'Buildkite' => fn(): CiPlan => BuildkitePlan::printing(sprintf('%s/steps.json', Scratch::directory()), [], $job()),
    'CircleCI' => fn(): CiPlan => CircleCiPlan::printing(sprintf('%s/plan.json', Scratch::directory()), $job()),
    'plain JSON' => fn(): CiPlan => JsonPlan::printing(sprintf('%s/plan.json', Scratch::directory()), $job()),
];

it('publishes a plan, even one with no shards', function (CiPlan $ci): void {
    expect($ci->publish(ShardedPlan::of(2)))->toBeInstanceOf(Written::class)
        ->and($ci->publish(ShardedPlan::of(0)))->toBeInstanceOf(Written::class);
})->with($plans);

it('names the shard this job runs', function (CiPlan $ci): void {
    expect($ci->shard(ShardedPlan::of(2)))->toEqual(ShardId::of(2));
})->with($plans);

it('cannot judge a plan that does not hold this job', function (CiPlan $ci): void {
    expect($ci->shard(ShardedPlan::of(1)))->toBeInstanceOf(CannotJudge::class);
})->with($plans);

it('says what it can of the run, or that it cannot tell', function (CiPlan $ci): void {
    $run = $ci->runOn();

    expect($run instanceof RunOn || $run->why() !== '')->toBeTrue();
})->with($plans);

it('names the CI definitions that run the gate as paths from the root', function (CiPlan $ci): void {
    foreach ($ci->definitions() as $definition) {
        expect($definition->value())->not->toStartWith('/')
            ->and($definition->value())->not->toBe('.');
    }

    expect($ci->definitions()->count())->toBeLessThanOrEqual(2);
})->with($plans);
