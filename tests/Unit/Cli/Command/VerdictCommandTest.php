<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\Printing;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Command\VerdictCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    Scratch::sweep();
});

/** How the command line composes a flow in a project over `src`, held to this floor; the fixture scores 40. */
$composed = static fn(string $project, float $floor): Composition => FlowCommands::over(
    Trees::of(Tree::at(Path::of('src'), Floor::of($floor), Package::at(Path::root()))),
    $project,
    ScriptedRunner::fixture(),
    new ProofStoreFake(),
    Flows::ci(),
    Variables::of([]),
);

/** A plan of two shards made, and both its shards run, leaving their results in this directory. */
$ran = static function (Composition $composition, string $results): void {
    FlowCommands::run(PlanCommand::command($composition), '--shards=2');

    foreach (['1', '2'] as $shard) {
        FlowCommands::run(
            RunCommand::command($composition),
            sprintf('--plan=.mutation-gate/plan.json --shard=%s --results=%s', $shard, $results),
        );
    }
};

it('judges the shards\' results, says what it wrote, prints the verdict and exits as it does', function (
    float $floor,
    int $code,
    string $tree,
) use ($composed, $ran): void {
    $project = FlowCommands::project();
    $composition = $composed($project, $floor);
    $ran($composition, '.mutation-gate/results');

    $verdict = FlowCommands::run(VerdictCommand::command($composition));

    expect($verdict->code)->toBe($code)
        ->and($verdict->errors)->toBe('')
        ->and($verdict->output)->toStartWith(sprintf(
            "Wrote memory:refs/heads/main.\nmutation-gate: %s\nThe project scores 40.00%%.\n\nTrees\n  %s\n",
            $code === 0 ? 'passed' : 'failed',
            $tree,
        ))
        ->and($verdict->output)->toContain('Reproduce: vendor/bin/mutation-gate reproduce');
})->with([
    'below its floor' => [50.0, 1, 'src scores 40.00%, below its floor of 50.00%.'],
    'at its floor' => [40.0, 0, 'src scores 40.00% against its floor of 40.00%.'],
]);

it('reads the plan and the results where it is told to', function () use ($composed, $ran): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 40);
    $ran($composition, 'shards');
    rename(sprintf('%s/.mutation-gate/plan.json', $project), sprintf('%s/the-plan.json', $project));

    $verdict = FlowCommands::run(VerdictCommand::command($composition), '--plan=the-plan.json --results=shards');

    expect($verdict->code)->toBe(0)
        ->and($verdict->output)->toStartWith("Wrote memory:refs/heads/main.\nmutation-gate: passed\n");
});

it('cannot judge without a plan, or with one it cannot read', function (
    string $how,
    string $why,
) use ($composed): void {
    $project = FlowCommands::project();

    if ($how === 'unreadable') {
        mkdir(sprintf('%s/.mutation-gate/plan.json', $project), recursive: true);
    }

    if ($how === 'not a plan') {
        Scratch::write($project, '.mutation-gate/plan.json', 'not a plan');
    }

    $verdict = FlowCommands::run(VerdictCommand::command($composed($project, 40)));

    expect($verdict->code)->toBe(2)
        ->and($verdict->output)->toBe('')
        ->and($verdict->errors)->toBe(sprintf("%s\n", str_replace('<project>', $project, $why)));
})->with([
    'no plan' => [
        'missing',
        'There is no plan at .mutation-gate/plan.json. Run mutation-gate plan, and hand its plan to every job.',
    ],
    'a plan it cannot read' => ['unreadable', '<project>/.mutation-gate/plan.json could not be read.'],
    'not a plan' => ['not a plan', 'The plan cannot be read, so no shard can follow it: the file.format is missing.'],
]);

it('cannot judge a shard that left no result', function () use ($composed): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 40);
    FlowCommands::run(PlanCommand::command($composition));

    $verdict = FlowCommands::run(VerdictCommand::command($composition));

    expect($verdict->code)->toBe(2)
        ->and($verdict->output)->toBe('')
        ->and($verdict->errors)->toStartWith('Shard 1 (');
});

it('cannot judge with a config it cannot read', function (): void {
    $project = FlowCommands::project('"shards": {"max": 0}');

    $verdict = FlowCommands::run(VerdictCommand::command(
        FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci()),
    ));

    expect($verdict->code)->toBe(2)
        ->and($verdict->errors)->toContain('shards.max:');
});

it('prints what was said beside a verdict, then the verdict, and exits as it does', function (): void {
    $output = new BufferedOutput();

    $code = VerdictCommand::printed(new Judged(Verdicts::passing(), ['Wrote a.json.', 'The disk is full.'], Baseline::none()), $output, Printing::console(), Directory::at(Scratch::directory()));

    expect($code)->toBe(0)
        ->and($output->fetch())->toStartWith("Wrote a.json.\nThe disk is full.\nmutation-gate: passed\n");
});

it('prints why there is no verdict, and exits 2', function (CannotJudge|Invalid $why, string $said): void {
    $output = new BufferedOutput();

    expect(VerdictCommand::printed($why, $output, Printing::console(), Directory::at(Scratch::directory())))->toBe(2)
        ->and($output->fetch())->toBe($said);
})->with([
    'cannot judge' => [CannotJudge::because('The runner failed.'), "The runner failed.\n"],
    'an invalid config' => [
        Invalid::because(Problem::at('reports[0]', 'no such reporter')),
        "reports[0]: no such reporter\n",
    ],
]);

it('offers the plan the shards ran and where they left their results', function () use ($composed): void {
    $definition = VerdictCommand::command($composed(FlowCommands::project(), 40))->getDefinition();

    expect($definition->getOption('plan')->isValueRequired())->toBeTrue()
        ->and($definition->getOption('results')->isValueRequired())->toBeTrue();
});
