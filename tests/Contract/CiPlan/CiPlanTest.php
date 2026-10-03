<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\AzurePlan;
use NightWorksIO\MutationGate\Adapter\Bitbucket\BitbucketPlan;
use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Adapter\Filesystem\PublicationFile;
use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

// What every CI plan answers: that it publishes any plan where it can be put,
// what it can say of the run, and which definitions run the gate. One line per
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
    'the fake' => fn(): CiPlan => new CiPlanFake(RunOn::branch('main', RunOn::branchNamed('main'))),
    'GitHub Actions' => fn(): CiPlan => GitHubPlan::in($job()),
    'GitLab CI' => fn(): CiPlan => GitLabPlan::writing(
        sprintf('%s/pipeline.yml', Scratch::directory()),
        'ci/gate.yml',
        $job(),
    ),
    'Buildkite' => fn(): CiPlan => BuildkitePlan::of(BuildkiteStep::none(), $job()),
    'CircleCI' => fn(): CiPlan => CircleCiPlan::in($job()),
    'Azure DevOps' => fn(): CiPlan => AzurePlan::in(CiJob::of($job(), Paths::of(Path::of('azure-pipelines.yml')))),
    'Bitbucket Pipelines' => fn(): CiPlan => BitbucketPlan::in(CiJob::of($job(), Paths::of(Path::of('bitbucket-pipelines.yml')))),
    'plain JSON' => fn(): CiPlan => JsonPlan::in($job()),
];

it('publishes a plan, even one with no shards, where it can be put', function (CiPlan $ci): void {
    $put = static function (Publication|CannotJudge $publication): Written|CannotJudge {
        ob_start();
        $written = $publication instanceof Publication ? PublicationFile::written($publication) : $publication;
        ob_end_clean();

        return $written;
    };

    expect($put($ci->publish(ShardedPlan::of(2))))->toBeInstanceOf(Written::class)
        ->and($put($ci->publish(ShardedPlan::of(0))))->toBeInstanceOf(Written::class);
})->with($plans);

it('says what it can of the run, or that it cannot tell', function (CiPlan $ci): void {
    $run = $ci->runOn();

    expect($run instanceof RunOn || $run->why() !== '')->toBeTrue();
})->with($plans);

it('names the CI definitions that run the gate as paths from the root', function (CiPlan $ci): void {
    $definitions = $ci->definitions();

    foreach ($definitions as $definition) {
        expect($definition->value())->not->toStartWith('/')
            ->and($definition->value())->not->toBe('.');
    }

    expect($definitions->count())->toBeLessThanOrEqual(2);
})->with($plans);

it('declares the credentials of its CI that no runner hands the tests, over and above what every run withholds', function (Withheld $declared): void {
    $withheld = Withheld::standard()->and($declared);

    expect(preg_match($withheld->pattern(), 'AWS_SECRET_ACCESS_KEY'))->toBe(1)
        ->and(preg_match($withheld->pattern(), 'PATH'))->toBe(0);
})->with([
    'the fake' => fn(): Withheld => CiPlanFake::withheld(),
    'GitHub Actions' => fn(): Withheld => GitHubPlan::withheld(),
    'GitLab CI' => fn(): Withheld => GitLabPlan::withheld(),
    'Buildkite' => fn(): Withheld => BuildkitePlan::withheld(),
    'CircleCI' => fn(): Withheld => CircleCiPlan::withheld(),
    'Azure DevOps' => fn(): Withheld => AzurePlan::withheld(),
    'Bitbucket Pipelines' => fn(): Withheld => BitbucketPlan::withheld(),
    'plain JSON' => fn(): Withheld => JsonPlan::withheld(),
]);
