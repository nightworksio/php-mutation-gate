<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Ci\Delivery;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\LogCommands;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

const BUILDKITE_DOWNLOAD = "buildkite-agent artifact download '.mutation-gate/**/*' .";

const BUILDKITE_VERDICT = 'vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json'
    . ' --results=.mutation-gate/results';

$stepsIn = static function (string $printed): array {
    $decoded = json_decode($printed, associative: true);

    return is_array($decoded) && is_array($decoded['steps'] ?? null) ? $decoded['steps'] : [];
};

$run = static fn(int $shard): string => sprintf(
    'vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json --shard=%d',
    $shard,
);

$wait = ['type' => 'wait', 'continue_on_failure' => true];

$verdict = [
    'label' => 'mutation: verdict',
    'key' => 'mutation-gate-verdict',
    'command' => [BUILDKITE_DOWNLOAD, BUILDKITE_VERDICT],
];

$on = static fn(Variables $variables): BuildkitePlan => BuildkitePlan::of(BuildkiteStep::none(), $variables);

it('prints a step per shard, a wait that continues on failure, and the verdict', function () use (
    $stepsIn,
    $run,
    $wait,
    $verdict,
): void {
    $published = BuildkitePlan::of(BuildkiteStep::none(), Variables::of([]))->publish(ShardedPlan::of(2));

    expect($published->delivery())->toBe(Delivery::Printed)
        ->and($stepsIn($published->text()))->toBe([
            [
                'label' => 'mutation: src, part 1 of 2',
                'key' => sprintf('mutation-gate-shard-%d', 1),
                'command' => [BUILDKITE_DOWNLOAD, $run(1)],
                'artifact_paths' => '.mutation-gate/results/1.json',
            ],
            [
                'label' => 'mutation: src, part 2 of 2',
                'key' => sprintf('mutation-gate-shard-%d', 2),
                'command' => [BUILDKITE_DOWNLOAD, $run(2)],
                'artifact_paths' => '.mutation-gate/results/2.json',
            ],
            $wait,
            $verdict,
        ]);
});

it('builds every command step from the template, whose commands run first', function () use (
    $stepsIn,
    $run,
    $wait,
): void {
    $template = ['agents' => ['queue' => 'mutation'], 'command' => 'composer install', 'env' => ['CI' => 'true']];
    $printed = BuildkitePlan::of(BuildkiteStep::of(Configs::options((string) json_encode($template))->written()), Variables::of([]))->publish(ShardedPlan::of(1))->text();

    expect($stepsIn($printed))->toBe([
        [
            'agents' => ['queue' => 'mutation'],
            'command' => ['composer install', BUILDKITE_DOWNLOAD, $run(1)],
            'env' => ['CI' => 'true'],
            'label' => 'mutation: src, part 1 of 1',
            'key' => sprintf('mutation-gate-shard-%d', 1),
            'artifact_paths' => '.mutation-gate/results/1.json',
        ],
        $wait,
        [
            'agents' => ['queue' => 'mutation'],
            'command' => ['composer install', BUILDKITE_DOWNLOAD, BUILDKITE_VERDICT],
            'env' => ['CI' => 'true'],
            'label' => 'mutation: verdict',
            'key' => 'mutation-gate-verdict',
        ],
    ]);
});

it('runs a template\'s list of commands first', function () use ($stepsIn): void {
    $template = ['command' => ['composer install', 'make warm']];
    $printed = BuildkitePlan::of(BuildkiteStep::of(Configs::options((string) json_encode($template))->written()), Variables::of([]))->publish(ShardedPlan::of(0))->text();

    expect($stepsIn($printed)[1])->toBe([
        'command' => ['composer install', 'make warm', BUILDKITE_DOWNLOAD, BUILDKITE_VERDICT],
        'label' => 'mutation: verdict',
        'key' => 'mutation-gate-verdict',
    ]);
});

it('refuses a template whose command is neither a command nor a list of them', function (string $command): void {
    expect(BuildkitePlan::fromOptions(Configs::options(sprintf('{"step": {"command": %s}, "definition": "ci.yml"}', $command))))
        ->toEqual(Invalid::because(Problem::at('step.command', 'expected a command, or a list of commands, as text')));
})->with(['a number' => ['7'], 'a list with a number' => ['["composer install", 7]']]);

it('prints only the wait and the verdict for a plan with no shards', function () use ($stepsIn, $wait, $verdict): void {
    $printed = BuildkitePlan::of(BuildkiteStep::none(), Variables::of([]))->publish(ShardedPlan::of(0))->text();

    expect($stepsIn($printed))->toBe([$wait, $verdict]);
});

it('reads a pull request, a branch and the default branch from Buildkite', function () use ($on): void {
    $main = RunOn::branchNamed('main');
    $pullRequest = Variables::of([
        'BUILDKITE_BRANCH' => 'feature',
        'BUILDKITE_PULL_REQUEST' => '42',
        'BUILDKITE_PIPELINE_DEFAULT_BRANCH' => 'main',
    ]);
    $push = Variables::of([
        'BUILDKITE_BRANCH' => 'main',
        'BUILDKITE_PULL_REQUEST' => 'false',
        'BUILDKITE_PIPELINE_DEFAULT_BRANCH' => 'main',
    ]);

    expect($on($pullRequest)->runOn())->toEqual(RunOn::pullRequest(PullRequestNumber::parse('42'), $main))
        ->and($on($push)->runOn())->toEqual(RunOn::branch('main', $main))
        ->and($on(Variables::of(['BUILDKITE_BRANCH' => 'feature']))->runOn())
        ->toEqual(RunOn::branch('feature', RunOn::branchNamed('')));
});

it('cannot tell the run where Buildkite names no branch', function () use ($on): void {
    expect($on(Variables::of([]))->runOn())
        ->toEqual(CannotTell::because(
            '"refs/heads/" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
        ));
});

it('prints to the output with the step template its options give', function () use ($stepsIn): void {
    $plan = BuildkitePlan::fromOptions(Configs::options('{"step": {"agents": {"queue": "mutation"}}, "definition": "ci.yml"}'));
    $printed = $plan instanceof BuildkitePlan ? $plan->publish(ShardedPlan::of(0))->text() : '';

    expect($stepsIn($printed)[1])->toBe([
        'agents' => ['queue' => 'mutation'],
        'label' => 'mutation: verdict',
        'key' => 'mutation-gate-verdict',
        'command' => [BUILDKITE_DOWNLOAD, BUILDKITE_VERDICT],
    ]);
});

it('takes no step template where its options name none', function () use ($stepsIn, $verdict): void {
    foreach (['{"definition": "ci.yml"}', '{"step": {}, "definition": "ci.yml"}'] as $options) {
        $plan = BuildkitePlan::fromOptions(Configs::options($options));
        $printed = $plan instanceof BuildkitePlan ? $plan->publish(ShardedPlan::of(0))->text() : '';

        expect($stepsIn($printed)[1])->toBe($verdict);
    }
});

it('refuses a step template that is not a map of step keys', function (): void {
    $refused = Invalid::because(Problem::at('step', 'The step template is a map of step keys.'));

    expect(BuildkitePlan::fromOptions(Configs::options('{"step": ["agents"]}')))->toEqual($refused)
        ->and(BuildkitePlan::fromOptions(Configs::options('{"step": "agents"}')))->toEqual($refused);
});

it('gives no scope to a tag, which is no branch the gate writes for', function () use ($on): void {
    $tag = Variables::of(['BUILDKITE_TAG' => 'v1', 'BUILDKITE_BRANCH' => 'v1', 'BUILDKITE_PIPELINE_DEFAULT_BRANCH' => 'main']);

    expect($on($tag)->runOn())->toEqual(RunOn::detached(RunOn::branchNamed('main')));
});

it('is run by the pipeline it uploads from the repository', function () use ($on): void {
    expect($on(Variables::of([]))->definitions())->toEqual(Paths::of(Path::of('.buildkite/pipeline.yml')));
});

it('withholds the agent\'s token', function (): void {
    expect(BuildkitePlan::withheld())->toEqual(Withheld::of('BUILDKITE_AGENT_ACCESS_TOKEN', 'BUILDKITE_AGENT_TOKEN'));
});

it('is run by the pipeline its options name, and refuses one that is not a path', function (): void {
    $definitions = static function (string $options): Paths|Invalid {
        $plan = BuildkitePlan::fromOptions(Configs::options($options));

        return $plan instanceof BuildkitePlan ? $plan->definitions() : $plan;
    };
    $missing = Invalid::because(Problem::at('definition', 'expected the pipeline that runs the gate, as a path'));

    expect($definitions('{"definition": ".buildkite/mutation.yml"}'))->toEqual(Paths::of(Path::of('.buildkite/mutation.yml')))
        ->and($definitions(Ci::none()->planOptions(Name::of('buildkite'))->written()->line()))
        ->toEqual(Paths::of(Path::of('.buildkite/pipeline.yml')))
        ->and($definitions('{"definition": 3}'))->toEqual(Invalid::because(Problem::at('definition', 'expected text, got 3')))
        ->and($definitions('{}'))->toEqual($missing)
        ->and($definitions('{"definition": ""}'))->toEqual($missing);
});

it('keeps its steps and variables when it is run by another pipeline', function () use ($on): void {
    $variables = Variables::of(['BUILDKITE_BRANCH' => 'feature', 'BUILDKITE_PIPELINE_DEFAULT_BRANCH' => 'main']);
    $plan = $on($variables)->definedIn(Path::of('ci/mutation.yml'));

    expect($plan->definitions())->toEqual(Paths::of(Path::of('ci/mutation.yml')))
        ->and($plan->runOn())->toEqual(RunOn::branch('feature', RunOn::branchNamed('main')))
        ->and($plan->publish(ShardedPlan::of(1)))->toEqual($on($variables)->publish(ShardedPlan::of(1)));
});

it('writes a label as the agent shows it, so a path a pull request names expands no variable', function () use ($stepsIn): void {
    $base = Digest::sha256Of('base');
    $plan = Plan::of(Revision::ref(ShardedPlan::COMMIT), $base, Keys::none(), Shards::of(Shard::of(
        ShardId::of(1),
        Package::at(Path::root()),
        Units::of(Unit::file(Path::of('src/$BUILDKITE_AGENT_ACCESS_TOKEN.php'))),
        Seconds::of(1.0),
        'src/$BUILDKITE_AGENT_ACCESS_TOKEN.php',
    )));

    $printed = BuildkitePlan::of(BuildkiteStep::none(), Variables::of([]))->publish($plan)->text();

    expect($stepsIn($printed)[0])->toMatchArray(['label' => 'mutation: src/$$BUILDKITE_AGENT_ACCESS_TOKEN.php']);
});

it('prints a pipeline whose labels hold log commands so a CI\'s log reads none, and Buildkite reads the labels back', function (): void {
    $printed = BuildkitePlan::of(BuildkiteStep::none(), Variables::of([]))->publish(ShardedPlan::hostile())->text();

    expect(LogCommands::in($printed))->toBe([])
        ->and(Decoded::at($printed, 'steps', 0, 'label'))->toBe(sprintf('mutation: %s', ShardedPlan::HOSTILE));
});
