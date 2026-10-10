<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\Printing;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Command\VerdictCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\LastRun;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\DeliveryFile;
use NightWorksIO\MutationGate\Core\Delivery\KeptPost;
use NightWorksIO\MutationGate\Core\Delivery\LedgerPost;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\HeldTo;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\Console\Input\ArrayInput;
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
            "Wrote memory:refs/heads/main.\nWrote memory:refs/heads/main/coverage.json.gz.\nmutation-gate: %s\nThe project scores 40.00%%.\n\nTrees\n  %s\n",
            $code === 0 ? 'passed' : 'failed',
            $tree,
        ))
        ->and($verdict->output)->toContain('Reproduce: vendor/bin/mutation-gate reproduce');
})->with([
    'below its floor' => [50.0, 1, 'src scores 40.00%, below its floor of 50.00%.'],
    'at its floor' => [40.0, 0, 'src scores 40.00% against its floor of 40.00%.'],
]);

it('judges the results held to the floors it is named, showing a tree it does not hold', function (
    HeldTo $heldTo,
    Judgement $judgement,
) use ($composed, $ran): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 50.0);
    $ran($composition, '.mutation-gate/results');
    $flow = $composition->compose(new ArrayInput([]));
    $plan = $flow instanceof Composed ? VerdictCommand::planIn($flow, Path::of('.mutation-gate/plan.json')) : $flow;
    $judged = $flow instanceof Composed && $plan instanceof Plan
        ? VerdictCommand::judgedOf($flow, $plan, Path::of('.mutation-gate/results'), $heldTo)
        : $plan;

    expect($judged instanceof Judged ? $judged->verdict->judgement() : $judged)->toBe($judgement)
        ->and($judged instanceof Judged ? count($judged->verdict->trees()) : $judged)->toBe(1);
})->with([
    'its trees' => [HeldTo::Trees, Judgement::Failed],
    'its new code alone' => [HeldTo::NewCode, Judgement::Passed],
]);

it('reads the plan and the results where it is told to', function () use ($composed, $ran): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 40);
    $ran($composition, 'shards');
    rename(sprintf('%s/.mutation-gate/plan.json', $project), sprintf('%s/the-plan.json', $project));

    $verdict = FlowCommands::run(VerdictCommand::command($composition), '--plan=the-plan.json --results=shards');

    expect($verdict->code)->toBe(0)
        ->and($verdict->output)->toStartWith("Wrote memory:refs/heads/main.\nWrote memory:refs/heads/main/coverage.json.gz.\nmutation-gate: passed\n");
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

it('says why it keeps no coverage map where the plan handed on none', function () use ($composed, $ran): void {
    $project = FlowCommands::project();
    $composition = $composed($project, 40);
    $ran($composition, '.mutation-gate/results');
    unlink(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project));

    $verdict = FlowCommands::run(VerdictCommand::command($composition));

    expect($verdict->output)->toStartWith(
        "Wrote memory:refs/heads/main.\nThe plan handed on no coverage map at .mutation-gate/coverage, so none is kept.\nmutation-gate: passed\n",
    );
});

it('leaves the ledger and the coverage map beside the verdict\'s delivery under --deliver-later, writing no store', function () use ($ran): void {
    $project = FlowCommands::project();
    $store = new ProofStoreFake();
    $composition = FlowCommands::over(
        Trees::of(Tree::at(Path::of('src'), Floor::of(40.0), Package::at(Path::root()))),
        $project,
        ScriptedRunner::fixture(),
        $store,
        Flows::ci(),
        Variables::of([]),
    );
    $ran($composition, '.mutation-gate/results');
    Scratch::write($project, '.mutation-gate/delivery/verdict/delivery.json', '{"format": 1, "comment": "an earlier run\'s"}');

    $verdict = FlowCommands::run(VerdictCommand::command($composition), '--deliver-later');
    $delivery = DeliveryFile::decode((string) file_get_contents(sprintf('%s/.mutation-gate/delivery/verdict/delivery.json', $project)));

    expect($verdict->code)->toBe(0)
        ->and($verdict->output)->toStartWith(sprintf(
            "Wrote %1\$s/.mutation-gate/delivery/verdict/ledger.json.gz. It is not in the store until deliver writes it there for refs/heads/main.\n"
            . "Wrote %1\$s/.mutation-gate/delivery/verdict/coverage.json.gz. It is not in the store until deliver keeps it there for refs/heads/main.\n",
            $project,
        ))
        ->and(array_filter($store->asked(), static fn(string $asked): bool => str_starts_with($asked, 'write')))->toBe([])
        ->and($store->companion(Scope::branch('main'), Companion::Coverage))->toBeInstanceOf(Missing::class)
        ->and($delivery)->toEqual(Delivery::none()
            ->withLedger(LedgerPost::to(Scope::branch('main')))
            ->withKept(KeptPost::of(Companion::Coverage, Scope::branch('main'))))
        ->and(is_file(sprintf('%s/.mutation-gate/delivery/verdict/ledger.json.gz', $project)))->toBeTrue()
        ->and(is_file(sprintf('%s/.mutation-gate/delivery/verdict/coverage.json.gz', $project)))->toBeTrue();
});

it('offers to leave what needs a credential for deliver', function () use ($composed): void {
    $definition = VerdictCommand::command($composed(FlowCommands::project(), 40))->getDefinition();

    expect($definition->getOption('deliver-later')->acceptValue())->toBeFalse();
});

it('plans nothing and passes, recording the commit, where only files that do not matter to the gate changed since the last that passed', function (): void {
    $project = FlowCommands::project();
    $proofs = new ProofStoreFake();
    $base = '5eeca8f0a1b2c3d4e5f60718293a4b5c6d7e8f90';
    $proofs->write(Scope::branch('main'), Ledger::empty()->withRuns(ScopeRuns::none()->passing(
        Passed::of(Revision::ref($base), 'mutation / verdict', 0)->passedAt(Instant::at(new DateTimeImmutable('2026-09-29T08:00:00Z'))),
    )));
    $composition = FlowCommands::reading(
        Trees::of(Tree::at(Path::of('src'), Floor::of(90.0), Package::at(Path::root()))),
        $project,
        ScriptedRunner::fixture(),
        $proofs,
        Flows::ci(),
        Variables::of([]),
        RepositoryFake::onMain(Revision::ref(Flows::HEAD)),
        new ChangeSourceFake(
            Revision::ref($base),
            Changes::of(Change::modified(Path::of('README.md'), Lines::none())),
            [Revision::workingTree()->name() => Flows::FILES, $base => Flows::FILES, Flows::MAIN => Flows::FILES],
        ),
    );
    $reason = sprintf('Nothing the gate judges changed since %s, whose verdict passed at 2026-09-29T08:00:00Z, so its verdict stands.', $base);

    $plan = FlowCommands::run(PlanCommand::command($composition));
    $verdict = FlowCommands::run(VerdictCommand::command($composition));
    $flow = $composition->compose(new ArrayInput([]));
    $again = $flow instanceof Composed ? LastRun::verdict($flow) : $flow;

    expect($plan->code)->toBe(0)
        ->and($plan->errors)->toContain(sprintf('Measured no coverage: %s', $reason))
        ->and($plan->errors)->toContain('Wrote .mutation-gate/plan.json, with 0 shards.')
        ->and($verdict->code)->toBe(0)
        ->and($verdict->output)->toStartWith("Wrote memory:refs/heads/main.\nmutation-gate: passed\n")
        ->and($verdict->output)->toContain($reason)
        ->and($again instanceof Verdict ? [$again->judgement(), count($again->trees())] : $again)->toBe([Judgement::Passed, 0])
        ->and(LedgerRead::ledger($proofs->read(Scope::branch('main')))->runs()->passed())
        ->toEqual(Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 0)->passedAt(Instant::at(new DateTimeImmutable(Configs::NOW))));
});
