<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\UnpublishedCi;

afterEach(function (): void {
    Scratch::sweep();
});

/** `plan` in a project, handed these options, run by this runner and handed to this CI. */
$plan = static fn(
    string $project,
    string $input,
    ScriptedRunner $runner,
    CiPlan $ci,
): FlowCommands => FlowCommands::run(
    PlanCommand::command(FlowCommands::composition($project, $runner, new ProofStoreFake(), $ci)),
    $input,
);

/** The unpatched Pest line, for a plan of this many shards. */
$unpatched = static fn(int $shards): string => sprintf(<<<'SAID'
    Pest runs without the gate's patch, so each of the %d shards pays a full opening run under coverage.
    Set pest.patch to true to have every shard reuse the map the plan kept.

    SAID, $shards);

it('writes the plan, hands it to the CI, and hands each shard its map', function () use ($plan): void {
    $project = FlowCommands::project();
    $ci = Flows::ci();

    $planned = $plan($project, '--shards=2', ScriptedRunner::fixture(), $ci);
    $written = PlanFile::decode((string) file_get_contents(sprintf('%s/.mutation-gate/plan.json', $project)));

    expect($planned->code)->toBe(0)
        ->and($planned->output)->toBe('')
        ->and($planned->errors)->toBe(<<<'SAID'
            Wrote .mutation-gate/plan.json, with 2 shards.
            Shard 1 (src, part 1 of 2): about 1m, guessed.
            Shard 2 (src, part 2 of 2): about 1m, guessed.
            The plan expects about 1m of wall time and 2m of runner time: 0% learned, 0% measured, 100% guessed.

            SAID)
        ->and($written)->toBeInstanceOf(Plan::class)
        ->and($ci->published)->toEqual([$written])
        ->and($written instanceof Plan ? count($written) : $written)->toBe(2)
        ->and(is_file(sprintf('%s/.mutation-gate/coverage/shard-2/map.json.gz', $project)))->toBeTrue();
});

it('leaves standard output to the plan a CI reads from it, and says what it wrote on standard error', function (
    CiPlan $printing,
) use ($plan): void {
    ob_start();
    $planned = $plan(FlowCommands::project(), '--shards=2', ScriptedRunner::fixture()->named('pest'), $printing);
    $stdout = sprintf('%s%s', ob_get_clean(), $planned->output);

    expect($planned->code)->toBe(0)
        ->and($stdout)->not->toBe('')
        ->and(json_validate($stdout))->toBeTrue()
        ->and($planned->errors)->toStartWith("Wrote .mutation-gate/plan.json, with 2 shards.\n");
})->with([
    'json' => [JsonPlan::in(Variables::of([]))],
    'circleci' => [CircleCiPlan::in(Variables::of([]))],
    'buildkite' => [BuildkitePlan::of(BuildkiteStep::none(), Variables::of([]))],
]);

it('cuts shards by the config\'s size where no count is asked for', function () use ($plan): void {
    $planned = $plan(FlowCommands::project(), '', ScriptedRunner::fixture(), Flows::ci());

    expect($planned->code)->toBe(0)
        ->and($planned->errors)->toStartWith("Wrote .mutation-gate/plan.json, with 1 shards.\nShard 1 (src): about");
});

it('warns where shards.max stops the plan meeting shards.target', function () use ($plan): void {
    $planned = $plan(FlowCommands::project('"shards": {"target": "30s", "max": 1}'), '', ScriptedRunner::fixture(), Flows::ci());

    expect($planned->code)->toBe(0)
        ->and($planned->errors)->toEndWith(<<<'SAID'
            shards.target is 30s, and at shards.max of 1 shards the longest is expected to take 1m.
            Raise shards.max, or shards.target, to meet it.

            SAID);
});

it('cannot plan with a config it cannot read', function () use ($plan): void {
    $planned = $plan(FlowCommands::project('"shards": {"max": 0}'), '', ScriptedRunner::fixture(), Flows::ci());

    expect($planned->code)->toBe(2)
        ->and($planned->output)->toBe('')
        ->and($planned->errors)->toContain('shards.max:');
});

it('cannot plan what the options contradict', function (string $input, string $why) use ($plan): void {
    $project = FlowCommands::project();
    $planned = $plan($project, $input, ScriptedRunner::fixture(), Flows::ci());

    expect($planned->code)->toBe(2)
        ->and($planned->errors)->toBe(sprintf("%s\n", $why))
        ->and(is_file(sprintf('%s/.mutation-gate/plan.json', $project)))->toBeFalse();
})->with([
    'a full run changed since a ref' => [
        '--full --changed-since=base',
        '--full and --changed-since ask for different runs. Give one of them.',
    ],
    'no shards' => ['--shards=0', '--shards=0 is not a number of shards.'],
    'a kill matrix it does not record' => ['--kill-matrix=every', '--kill-matrix takes first or full, not "every".'],
]);

it('writes into the plan the kill matrix the run records', function (string $input, MatrixKind $matrix) use ($plan): void {
    $project = FlowCommands::project();
    $planned = $plan($project, $input, ScriptedRunner::fixture(), Flows::ci());
    $written = PlanFile::decode((string) file_get_contents(sprintf('%s/.mutation-gate/plan.json', $project)));

    expect($planned->code)->toBe(0)
        ->and($written instanceof Plan ? $written->briefing()->matrix() : $written)->toBe($matrix);
})->with([
    'every killer' => ['--kill-matrix=full', MatrixKind::Full],
    'first killers, as asked' => ['--kill-matrix=first', MatrixKind::FirstKiller],
    'first killers, unasked' => ['', MatrixKind::FirstKiller],
]);

it('cannot plan where the runner cannot run the suite', function () use ($plan): void {
    $runner = ScriptedRunner::fixture()->unnamed('No runner is installed.');
    $planned = $plan(FlowCommands::project(), '', $runner, Flows::ci());

    expect($planned->code)->toBe(2)
        ->and($planned->errors)->toBe("No runner is installed.\n");
});

it('cannot hand a plan it could not write to the CI', function () use ($plan): void {
    $project = FlowCommands::project();
    mkdir(sprintf('%s/.mutation-gate/plan.json', $project), recursive: true);
    $ci = Flows::ci();

    $planned = $plan($project, '', ScriptedRunner::fixture(), $ci);

    expect($planned->code)->toBe(2)
        ->and($planned->errors)->toBe(sprintf("%s/.mutation-gate/plan.json could not be written.\n", $project))
        ->and($ci->published)->toBe([]);
});

it('cannot plan where the CI cannot take the plan', function () use ($plan): void {
    $ci = new UnpublishedCi('GITHUB_OUTPUT is not set.');
    $planned = $plan(FlowCommands::project(), '', ScriptedRunner::fixture(), $ci);

    expect($planned->code)->toBe(2)
        ->and($planned->output)->toBe('')
        ->and($planned->errors)->toBe("GITHUB_OUTPUT is not set.\n");
});

it('says each shard pays a full opening run where Pest runs sharded without the patch', function () use (
    $plan,
    $unpatched,
): void {
    $planned = $plan(FlowCommands::project(), '--shards=2', ScriptedRunner::fixture()->likePest(), Flows::ci());

    expect($planned->code)->toBe(0)
        ->and($planned->errors)->toStartWith("Wrote .mutation-gate/plan.json, with 2 shards.\n")
        ->and($planned->errors)->toEndWith($unpatched(2));
});

it('says nothing of the patch where the plan has one shard, or no shard pays its own opening run', function (
    string $shards,
    ScriptedRunner $runner,
) use ($plan): void {
    $planned = $plan(FlowCommands::project(), sprintf('--shards=%s', $shards), $runner, Flows::ci());

    expect($planned->errors)->toStartWith(sprintf("Wrote .mutation-gate/plan.json, with %s shards.\n", $shards))
        ->and($planned->errors)->not->toContain('opening run under coverage');
})->with([
    'Pest with the patch' => ['2', ScriptedRunner::fixture()->likePatchedPest(Group::named('mutation-canary'))],
    'one shard' => ['1', ScriptedRunner::fixture()->likePest()],
    'another runner' => ['2', ScriptedRunner::fixture()],
]);

it('asks the runner who it is withholding every CI\'s tokens', function () use ($plan): void {
    $runner = ScriptedRunner::fixture()->likePest();
    $plan(FlowCommands::project(), '--shards=2', $runner, Flows::ci());
    $identified = $runner->identified();

    expect($identified)->not->toBeEmpty()
        ->and($identified)->each->toEqual($identified[0])
        ->and(preg_match($identified[0]->pattern(), 'CI_JOB_TOKEN'))->toBe(1);
});

it('writes the sticky comment in its planned state on a pull request\'s run, and says what it said', function (): void {
    $project = FlowCommands::project('"ci": {"plan": "json"}');
    $composition = FlowCommands::over(
        Flows::trees(),
        $project,
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_TOKEN' => 'secret']),
    );
    $planned = Environment::during(
        ['GITHUB_EVENT_NAME' => 'push'],
        static fn(): FlowCommands => FlowCommands::run(PlanCommand::command($composition), '--shards=1'),
    );

    expect($planned->code)->toBe(0)
        ->and($planned->errors)->toEndWith("This run is not for a pull request, so there is no comment to write.\n");
});

it('writes no planned state where the run is not a pull request\'s', function () use ($plan): void {
    $planned = $plan(FlowCommands::project(), '--shards=1', ScriptedRunner::fixture(), Flows::ci());

    expect($planned->errors)->not->toContain('comment');
});
