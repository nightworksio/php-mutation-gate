<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Delivery;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

const GITLAB_VERDICT = 'vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json'
    . ' --results=.mutation-gate/results';

$fromThePlan = ['pipeline' => '$PARENT_PIPELINE_ID', 'job' => 'mutation-plan'];

$pipelineIn = static fn(Publication|CannotJudge $published): mixed => json_decode(
    $published instanceof Publication ? $published->text() : $published->why(),
    associative: true,
);

$planJob = static fn(string $name): Variables => Variables::of(['CI_JOB_NAME' => $name]);

$on = static fn(Variables $variables): GitLabPlan => GitLabPlan::writing('', '', $variables);

it('writes a child pipeline with a matrix of the shards and a verdict that always runs', function () use (
    $fromThePlan,
    $pipelineIn,
    $planJob,
): void {
    $gitlab = GitLabPlan::writing('build/pipeline.yml', '.gitlab/mutation-gate.yml', $planJob('mutation-plan'));
    $published = $gitlab->publish(ShardedPlan::of(2));

    expect($published instanceof Publication ? [$published->to(), $published->delivery()] : $published)
        ->toBe(['build/pipeline.yml', Delivery::Written])
        ->and($pipelineIn($published))->toBe([
            'include' => [['local' => '.gitlab/mutation-gate.yml']],
            'mutation-gate-shard' => [
                'extends' => '.mutation-gate',
                'needs' => [$fromThePlan],
                'parallel' => ['matrix' => [['SHARD' => ['1', '2']]]],
                'script' => ['vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json'],
                'artifacts' => ['when' => 'always', 'paths' => ['.mutation-gate/results/']],
            ],
            'mutation-gate-verdict' => [
                'extends' => '.mutation-gate',
                'needs' => [$fromThePlan, ['job' => 'mutation-gate-shard']],
                'when' => 'always',
                'script' => [GITLAB_VERDICT],
            ],
        ]);
});

it('writes the pipeline as JSON, which GitLab reads as YAML', function () use ($planJob): void {
    expect(GitLabPlan::writing('pipeline.yml', '.gitlab/mutation-gate.yml', $planJob('plan'))->publish(ShardedPlan::of(0)))
        ->toEqual(Publication::written('pipeline.yml', JsonText::encode([
            'include' => [['local' => '.gitlab/mutation-gate.yml']],
            'mutation-gate-verdict' => [
                'extends' => '.mutation-gate',
                'needs' => [['pipeline' => '$PARENT_PIPELINE_ID', 'job' => 'plan']],
                'when' => 'always',
                'script' => [GITLAB_VERDICT],
            ],
        ])));
});

it('leaves the matrix job out of a plan with no shards, and the verdict still runs', function () use (
    $fromThePlan,
    $pipelineIn,
    $planJob,
): void {
    $published = GitLabPlan::writing('pipeline.yml', 'ci/gate.yml', $planJob('mutation-plan'))->publish(ShardedPlan::of(0));

    expect($pipelineIn($published))->toBe([
        'include' => [['local' => 'ci/gate.yml']],
        'mutation-gate-verdict' => [
            'extends' => '.mutation-gate',
            'needs' => [$fromThePlan],
            'when' => 'always',
            'script' => [GITLAB_VERDICT],
        ],
    ]);
});

it('cannot name the job that made the plan outside a GitLab job', function (): void {
    expect(GitLabPlan::writing('pipeline.yml', 'ci/gate.yml', Variables::of([]))->publish(ShardedPlan::of(1)))
        ->toEqual(CannotJudge::because(
            'CI_JOB_NAME is not set, so the child pipeline cannot name the job that planned. Plan in a GitLab job.',
        ));
});

it('reads a merge request, a branch and the default branch from GitLab', function () use ($on): void {
    $main = RunOn::branchNamed('main');
    $mergeRequest = Variables::of([
        'CI_COMMIT_REF_NAME' => 'feature',
        'CI_MERGE_REQUEST_IID' => '7',
        'CI_DEFAULT_BRANCH' => 'main',
    ]);
    $push = Variables::of(['CI_COMMIT_REF_NAME' => 'feature', 'CI_DEFAULT_BRANCH' => 'main']);
    $unnamed = CannotTell::because(
        '"refs/heads/" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
    );

    expect($on($mergeRequest)->runOn())->toEqual(RunOn::pullRequest(PullRequestNumber::parse('7'), $main))
        ->and($on($push)->runOn())->toEqual(RunOn::branch('feature', $main))
        ->and($on(Variables::of(['CI_COMMIT_REF_NAME' => 'feature']))->runOn())
        ->toEqual(RunOn::branch('feature', $unnamed));
});

it('writes its pipeline where the gate expects it, with the named template', function () use ($pipelineIn): void {
    $before = getenv('CI_JOB_NAME');
    putenv('CI_JOB_NAME=mutation-plan');
    $default = GitLabPlan::fromOptions(Ci::none()->planOptions(Name::of('gitlab')));
    $wrote = $default instanceof GitLabPlan ? $default->publish(ShardedPlan::of(0)) : $default;
    $named = GitLabPlan::fromOptions(Configs::options('{"template": "ci/gate.yml"}'));
    $wroteNamed = $named instanceof GitLabPlan ? $named->publish(ShardedPlan::of(0)) : $named;
    putenv($before === false ? 'CI_JOB_NAME' : sprintf('CI_JOB_NAME=%s', $before));
    $to = static fn(Publication|Invalid|CannotJudge $published): string => $published instanceof Publication
        ? $published->to()
        : '';

    expect(GitLabPlan::PIPELINE)->toBe('.mutation-gate/pipeline.yml')
        ->and(Ci::none()->gitlabTemplate()->value())->toBe('.gitlab/mutation-gate.yml')
        ->and($to($wrote))->toBe('.mutation-gate/pipeline.yml')
        ->and($to($wroteNamed))->toBe('.mutation-gate/pipeline.yml')
        ->and($wrote instanceof Invalid ? $wrote : $pipelineIn($wrote))
        ->toMatchArray(['include' => [['local' => '.gitlab/mutation-gate.yml']]])
        ->and($wroteNamed instanceof Invalid ? $wroteNamed : $pipelineIn($wroteNamed))
        ->toMatchArray(['include' => [['local' => 'ci/gate.yml']]]);
});

it('refuses a template that is not written as text, or not given', function (): void {
    expect(GitLabPlan::fromOptions(Configs::options('{"template": ["ci/gate.yml"]}')))
        ->toEqual(Invalid::because(Problem::at('template', 'expected text, got a list')))
        ->and(GitLabPlan::fromOptions(Options::none()))->toEqual(Invalid::because(
            Problem::at('template', 'expected the file that defines the hidden .mutation-gate job, got nothing'),
        ));
});

it('gives no scope to a tag, which is no branch the gate writes for', function () use ($on): void {
    $tag = Variables::of(['CI_COMMIT_TAG' => 'v1', 'CI_COMMIT_REF_NAME' => 'v1', 'CI_DEFAULT_BRANCH' => 'main']);

    expect($on($tag)->runOn())->toEqual(RunOn::detached(RunOn::branchNamed('main')));
});

it('is run by the pipeline CI_CONFIG_PATH names, .gitlab-ci.yml by default, and by the template', function (): void {
    $named = GitLabPlan::writing('', 'ci/gate.yml', Variables::of(['CI_CONFIG_PATH' => 'ci/main.yml']));

    expect($named->definitions())->toEqual(Paths::of(Path::of('ci/main.yml'), Path::of('ci/gate.yml')))
        ->and(GitLabPlan::writing('', 'ci/gate.yml', Variables::of([]))->definitions())
        ->toEqual(Paths::of(Path::of('.gitlab-ci.yml'), Path::of('ci/gate.yml')));
});

it('withholds the job\'s token, its signed identity and the registry\'s and deploy passwords', function (): void {
    $withheld = GitLabPlan::withheld();

    foreach (['CI_JOB_TOKEN', 'CI_JOB_JWT_V2', 'CI_REGISTRY_PASSWORD', 'CI_DEPLOY_PASSWORD', 'CI_DEPENDENCY_PROXY_PASSWORD'] as $name) {
        expect(preg_match($withheld->pattern(), $name))->toBe(1);
    }

    expect(preg_match($withheld->pattern(), 'CI_JOB_ID'))->toBe(0);
});
