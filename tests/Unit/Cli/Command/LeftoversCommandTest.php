<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\LeftoversCommand;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\LeftAtTheDeadline;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A project over `src` whose plan of two shards was made and both shards run, leaving their results in this directory.
 *
 * @return array{string, Composition}
 */
function leftoversRan(string $results): array
{
    $project = FlowCommands::project();
    $composition = FlowCommands::over(
        Trees::of(Tree::at(Path::of('src'), Floor::of(40.0), Package::at(Path::root()))),
        $project,
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of([]),
    );
    FlowCommands::run(PlanCommand::command($composition), '--shards=2');

    foreach (['1', '2'] as $shard) {
        FlowCommands::run(
            RunCommand::command($composition),
            sprintf('--plan=.mutation-gate/plan.json --shard=%s --results=%s', $shard, $results),
        );
    }

    return [$project, $composition];
}

it('prints the second wave\'s matrix of the shares it cut and wrote beside the results, none where no shard left a mutant', function (bool $stopped, string $matrix): void {
    $ran = leftoversRan('shards');
    [$project, $composition] = $ran;
    $file = sprintf('%s/shards/1.json', $project);
    $whole = ShardResultFile::decode((string) file_get_contents($file));

    if ($stopped && $whole instanceof ShardResult) {
        file_put_contents($file, ShardResultFile::encode(LeftAtTheDeadline::resultOf(
            $whole,
            static fn(Mutant $mutant): Mutant => $mutant->unjudged(OutOfTime::BeforeRunning),
        )));
    }

    $cut = FlowCommands::run(LeftoversCommand::command($composition), '--results=shards');
    $written = LeftoverShares::decode((string) file_get_contents(sprintf('%s/shards/leftovers.json', $project)));

    expect([$cut->code, $cut->output, $cut->errors])->toBe([0, sprintf("%s\n", $matrix), ''])
        ->and($written instanceof LeftoverShares ? $written->matrix() : $written)->toBe(json_decode($matrix, associative: true));
})->with([
    'one shard stopped' => [true, '[{"shard":1,"share":1}]'],
    'none stopped' => [false, '[]'],
]);

it('cuts no second wave where a shard left no result, as the verdict cannot judge the run, saying why on its error output', function (): void {
    [$project, $composition] = leftoversRan('shards');
    $file = sprintf('%s/shards/1.json', $project);
    $whole = ShardResultFile::decode((string) file_get_contents($file));

    if ($whole instanceof ShardResult) {
        file_put_contents($file, ShardResultFile::encode(LeftAtTheDeadline::resultOf(
            $whole,
            static fn(Mutant $mutant): Mutant => $mutant->unjudged(OutOfTime::BeforeRunning),
        )));
    }

    unlink(sprintf('%s/shards/2.json', $project));

    $cut = FlowCommands::run(LeftoversCommand::command($composition), '--results=shards');
    $written = LeftoverShares::decode((string) file_get_contents(sprintf('%s/shards/leftovers.json', $project)));

    expect([$cut->code, $cut->output])->toBe([0, "[]\n"])
        ->and($cut->errors)->toStartWith("No second wave is cut: its jobs would change no verdict (ADR-0301).\nThe verdict cannot judge this run whatever a second wave judges:\nShard 2 (")
        ->and($written instanceof LeftoverShares ? $written->matrix() : $written)->toBe([]);
});

it('cannot cut without a plan', function (): void {
    $ran = leftoversRan('.mutation-gate/results');
    unlink(sprintf('%s/.mutation-gate/plan.json', $ran[0]));

    $cut = FlowCommands::run(LeftoversCommand::command($ran[1]));

    expect([$cut->code, $cut->output, $cut->errors])->toBe([
        2,
        '',
        "There is no plan at .mutation-gate/plan.json. Run vendor/bin/mutation-gate plan, and hand its plan to every job.\n",
    ]);
});

it('has the second wave judge the shares it cut, run --leftover leaving each share\'s result where the verdict reads it, as the shard would have', function (): void {
    [$project, $composition] = leftoversRan('shards');
    $file = sprintf('%s/shards/1.json', $project);
    $whole = ShardResultFile::decode((string) file_get_contents($file));
    $wholly = $whole instanceof ShardResult ? array_map(
        static fn(Mutant $mutant): string => sprintf('%s %s', $mutant->id()->value(), $mutant->status()->value),
        LeftAtTheDeadline::mutantsOf($whole),
    ) : [];
    file_put_contents($file, $whole instanceof ShardResult ? ShardResultFile::encode(LeftAtTheDeadline::resultOf(
        $whole,
        static fn(Mutant $mutant): Mutant => $mutant->unjudged(OutOfTime::BeforeRunning),
    )) : '');
    FlowCommands::run(LeftoversCommand::command($composition), '--results=shards');

    $ran = FlowCommands::run(RunCommand::command($composition), '--plan=.mutation-gate/plan.json --shard=1 --leftover=1 --results=shards');
    $plan = PlanFile::decode((string) file_get_contents(sprintf('%s/.mutation-gate/plan.json', $project)));
    $results = $plan instanceof Plan ? Results::read($plan, Path::of('shards'), Directory::at($project)) : $plan;
    $judged = $results instanceof Results ? array_merge(...array_map(
        static fn(UnitResult $unit): array => array_map(
            static fn(Mutant $mutant): string => sprintf('%s %s', $mutant->id()->value(), $mutant->status()->value),
            [...$unit->mutants()],
        ),
        [...$results->units()],
    )) : [];

    expect($ran->code)->toBe(0)
        ->and(is_file(sprintf('%s/shards/1.leftover-1.json', $project)))->toBeTrue()
        ->and(array_values(array_intersect($judged, $wholly)))->toBe($wholly)
        ->and($wholly)->not->toBe([]);
});

it('cannot judge a share it is not given, of no shard, or that the shares do not list', function (string $options, string $said): void {
    [, $composition] = leftoversRan('shards');

    $ran = FlowCommands::run(RunCommand::command($composition), sprintf('--plan=.mutation-gate/plan.json --results=shards %s', $options));

    expect([$ran->code, $ran->errors])->toBe([2, $said]);
})->with([
    'not a number' => ['--shard=1 --leftover=x', "--leftover=x is not a share number.\n"],
    'no shard' => ['--leftover=1', "A job of the second wave judges a share of one shard's left mutants, so run --leftover takes --shard too.\n"],
    'no shares' => ['--shard=1 --leftover=1', <<<'SAID'
        No shares of the mutants the shards left are at shards/leftovers.json, so no share of them can be judged.
        Cut them with `mutation-gate leftovers` after the shards, and hand them to each job of the second wave.

        SAID],
]);

it('cannot judge a share the shares cut from this plan do not list', function (): void {
    [, $composition] = leftoversRan('shards');
    FlowCommands::run(LeftoversCommand::command($composition), '--results=shards');

    $ran = FlowCommands::run(RunCommand::command($composition), '--plan=.mutation-gate/plan.json --shard=1 --leftover=1 --results=shards');

    expect([$ran->code, $ran->errors])->toBe([2, <<<'SAID'
        The shares cut from this plan list no share 1 of the mutants shard 1 left, so it cannot be judged.
        Run each job of the second wave for a share the matrix `mutation-gate leftovers` printed names.

        SAID]);
});
