<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
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
        ->and($planned->output)->toBe("Wrote .mutation-gate/plan.json, with 2 shards.\n")
        ->and($planned->errors)->toBe('')
        ->and($written)->toBeInstanceOf(Plan::class)
        ->and($ci->published)->toEqual([$written])
        ->and($written instanceof Plan ? count($written) : $written)->toBe(2)
        ->and(is_file(sprintf('%s/.mutation-gate/coverage/shard-2/map.json.gz', $project)))->toBeTrue();
});

it('cuts shards by the config\'s size where no count is asked for', function () use ($plan): void {
    $planned = $plan(FlowCommands::project(), '', ScriptedRunner::fixture(), Flows::ci());

    expect($planned->code)->toBe(0)
        ->and($planned->output)->toBe("Wrote .mutation-gate/plan.json, with 1 shards.\n");
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
    $planned = $plan(FlowCommands::project(), '--shards=2', ScriptedRunner::fixture()->named('pest'), Flows::ci());

    expect($planned->code)->toBe(0)
        ->and($planned->output)->toBe(sprintf("Wrote .mutation-gate/plan.json, with 2 shards.\n%s", $unpatched(2)));
});

it('says nothing of the patch where it is on, the plan has one shard, or the runner is not Pest', function (
    string $config,
    string $shards,
    ScriptedRunner $runner,
) use ($plan): void {
    $planned = $plan(FlowCommands::project($config), sprintf('--shards=%s', $shards), $runner, Flows::ci());

    expect($planned->output)->toBe(sprintf("Wrote .mutation-gate/plan.json, with %s shards.\n", $shards));
})->with([
    'the patch on' => ['"pest": {"patch": true}', '2', ScriptedRunner::fixture()->named('pest')],
    'one shard' => ['', '1', ScriptedRunner::fixture()->named('pest')],
    'another runner' => ['', '2', ScriptedRunner::fixture()->named('infection')],
]);
