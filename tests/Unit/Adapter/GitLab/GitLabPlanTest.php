<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

afterEach(function (): void {
    Scratch::sweep();
});

const GITLAB_VERDICT = 'vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json'
    . ' --results=.mutation-gate/results';

$fromThePlan = ['pipeline' => '$PARENT_PIPELINE_ID', 'job' => 'mutation-plan'];

$pipelineIn = static fn(string $file): mixed => json_decode((string) file_get_contents($file), associative: true);

$planJob = static fn(string $name): Variables => Variables::of(['CI_JOB_NAME' => $name]);

$on = static fn(Variables $variables): GitLabPlan => GitLabPlan::writing('', '', $variables);

it('writes a child pipeline with a matrix of the shards and a verdict that always runs', function () use (
    $fromThePlan,
    $pipelineIn,
    $planJob,
): void {
    $pipeline = sprintf('%s/.mutation-gate/pipeline.yml', Scratch::directory());
    $gitlab = GitLabPlan::writing($pipeline, '.gitlab/mutation-gate.yml', $planJob('mutation-plan'));

    expect($gitlab->publish(ShardedPlan::of(2)))->toEqual(Written::to($pipeline))
        ->and($pipelineIn($pipeline))->toBe([
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

it('makes every directory the pipeline needs', function () use ($planJob): void {
    $pipeline = sprintf('%s/build/.mutation-gate/pipeline.yml', Scratch::directory());

    expect(GitLabPlan::writing($pipeline, 'ci/gate.yml', $planJob('plan'))->publish(ShardedPlan::of(0)))
        ->toEqual(Written::to($pipeline))
        ->and(is_file($pipeline))->toBeTrue();
});

it('writes the pipeline as JSON, which GitLab reads as YAML', function () use ($planJob): void {
    $pipeline = sprintf('%s/pipeline.yml', Scratch::directory());
    GitLabPlan::writing($pipeline, '.gitlab/mutation-gate.yml', $planJob('plan'))->publish(ShardedPlan::of(0));

    expect(file_get_contents($pipeline))->toBe(Json::encode([
        'include' => [['local' => '.gitlab/mutation-gate.yml']],
        'mutation-gate-verdict' => [
            'extends' => '.mutation-gate',
            'needs' => [['pipeline' => '$PARENT_PIPELINE_ID', 'job' => 'plan']],
            'when' => 'always',
            'script' => [GITLAB_VERDICT],
        ],
    ]));
});

it('leaves the matrix job out of a plan with no shards, and the verdict still runs', function () use (
    $fromThePlan,
    $pipelineIn,
    $planJob,
): void {
    $pipeline = sprintf('%s/pipeline.yml', Scratch::directory());
    GitLabPlan::writing($pipeline, 'ci/gate.yml', $planJob('mutation-plan'))->publish(ShardedPlan::of(0));

    expect($pipelineIn($pipeline))->toBe([
        'include' => [['local' => 'ci/gate.yml']],
        'mutation-gate-verdict' => [
            'extends' => '.mutation-gate',
            'needs' => [$fromThePlan],
            'when' => 'always',
            'script' => [GITLAB_VERDICT],
        ],
    ]);
});

it('writes into a directory that is already there', function () use ($planJob): void {
    $pipeline = sprintf('%s/pipeline.yml', Scratch::directory());

    expect(GitLabPlan::writing($pipeline, 'ci/gate.yml', $planJob('plan'))->publish(ShardedPlan::of(1)))
        ->toEqual(Written::to($pipeline));
});

it('cannot name the job that made the plan outside a GitLab job', function (): void {
    $pipeline = sprintf('%s/pipeline.yml', Scratch::directory());

    expect(GitLabPlan::writing($pipeline, 'ci/gate.yml', Variables::of([]))->publish(ShardedPlan::of(1)))
        ->toEqual(CannotJudge::because(
            'CI_JOB_NAME is not set, so the child pipeline cannot name the job that planned. Plan in a GitLab job.',
        ))
        ->and(file_exists($pipeline))->toBeFalse();
});

it('cannot judge a pipeline it cannot write', function () use ($planJob): void {
    $root = Scratch::directory();
    Scratch::write($root, 'file', 'a file where a directory should be');
    mkdir(sprintf('%s/taken.yml', $root));
    $underAFile = GitLabPlan::writing(sprintf('%s/file/pipeline.yml', $root), 'ci/gate.yml', $planJob('plan'));
    $overADirectory = GitLabPlan::writing(sprintf('%s/taken.yml', $root), 'ci/gate.yml', $planJob('plan'));

    set_error_handler(static fn(): bool => true);
    $notUnder = $underAFile->publish(ShardedPlan::of(1));
    $notOver = $overADirectory->publish(ShardedPlan::of(1));
    restore_error_handler();

    expect($notUnder)->toEqual(CannotJudge::because(sprintf('%s/file/pipeline.yml could not be written.', $root)))
        ->and($notOver)->toEqual(CannotJudge::because(sprintf('%s/taken.yml could not be written.', $root)));
});

it('names the shard its matrix or its parallel job was started as', function () use ($on): void {
    expect($on(Variables::of(['SHARD' => '3']))->shard(ShardedPlan::of(3)))->toEqual(ShardId::of(3))
        ->and($on(Variables::of(['CI_NODE_INDEX' => '1', 'CI_NODE_TOTAL' => '2']))->shard(ShardedPlan::of(2)))
        ->toEqual(ShardId::of(1));
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

    expect($on($mergeRequest)->runOn())->toEqual(RunOn::pullRequest('7', $main))
        ->and($on($push)->runOn())->toEqual(RunOn::branch('feature', $main))
        ->and($on(Variables::of(['CI_COMMIT_REF_NAME' => 'feature']))->runOn())
        ->toEqual(RunOn::branch('feature', $unnamed));
});

it('writes its pipeline where the gate expects it, with the named template', function () use ($pipelineIn): void {
    $root = Scratch::directory();
    $before = getenv('CI_JOB_NAME');
    $directory = (string) getcwd();
    putenv('CI_JOB_NAME=mutation-plan');
    chdir($root);
    $default = GitLabPlan::fromOptions(Options::none());
    $wrote = $default instanceof GitLabPlan ? $default->publish(ShardedPlan::of(0)) : $default;
    $included = $pipelineIn(sprintf('%s/.mutation-gate/pipeline.yml', $root));
    $named = GitLabPlan::fromOptions(Options::ofJson('{"template": "ci/gate.yml"}'));
    $wroteNamed = $named instanceof GitLabPlan ? $named->publish(ShardedPlan::of(0)) : $named;
    chdir($directory);
    putenv($before === false ? 'CI_JOB_NAME' : sprintf('CI_JOB_NAME=%s', $before));

    expect(GitLabPlan::PIPELINE)->toBe('.mutation-gate/pipeline.yml')
        ->and(GitLabPlan::TEMPLATE)->toBe('.gitlab/mutation-gate.yml')
        ->and($wrote)->toEqual(Written::to('.mutation-gate/pipeline.yml'))
        ->and($wroteNamed)->toEqual(Written::to('.mutation-gate/pipeline.yml'))
        ->and($included)->toMatchArray(['include' => [['local' => '.gitlab/mutation-gate.yml']]])
        ->and($pipelineIn(sprintf('%s/.mutation-gate/pipeline.yml', $root)))
        ->toMatchArray(['include' => [['local' => 'ci/gate.yml']]]);
});

it('refuses a template that is not written as text', function (): void {
    expect(GitLabPlan::fromOptions(Options::ofJson('{"template": ["ci/gate.yml"]}')))
        ->toEqual(Invalid::because(Problem::at('template', 'The template is a path, written as text.')));
});
