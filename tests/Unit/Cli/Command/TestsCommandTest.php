<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Command\TestsCommand;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

it('prints the tests report of the last run, running nothing, and exits 0 whatever it finds', function (): void {
    $runner = ScriptedRunner::fixture();
    $store = new ProofStoreFake();
    $composition = FlowCommands::composition(FlowCommands::project(), $runner, $store, Flows::ci());
    FlowCommands::run(RunCommand::command($composition), '--full');
    $ledger = $store->read(Scope::branch('main'));

    $reported = FlowCommands::run(TestsCommand::command($composition));

    expect($reported->code)->toBe(0)
        ->and($reported->errors)->toBe('')
        ->and($reported->output)->toBe(<<<'SAID'
            Tests mutation cannot see
            This judges mutation kills only, and never fails anything.

            Kills nothing it covers (1)
              tests/HeldTest.php::it doubles  judged 1

            Never the first to kill (0)
              None.

            Removable
              Needs a full kill matrix: run `mutation-gate run --kill-matrix=full`.

            Asserts only existence or shape (0)
              None.

            SAID)
        ->and($store->read(Scope::branch('main')))->toEqual($ledger);
});

it('prints a test name as it is, never as console markup', function (): void {
    $project = FlowCommands::project();
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci());
    FlowCommands::run(RunCommand::command($composition), '--full');
    $plan = sprintf('%s/.mutation-gate/plan.json', $project);
    file_put_contents($plan, str_replace('it doubles', 'it doubles <info>twice</info>', (string) file_get_contents($plan)));

    $reported = FlowCommands::run(TestsCommand::command($composition));

    expect($reported->output)->toContain("\n  tests/HeldTest.php::it doubles <info>twice</info>  judged 1\n");
});

it('exits 2 where no run has left anything to report on', function (): void {
    $composition = FlowCommands::composition(FlowCommands::project(), ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci());
    $reported = FlowCommands::run(TestsCommand::command($composition));

    expect([$reported->code, $reported->output, $reported->errors])->toBe([
        2,
        '',
        "No run has left a plan at .mutation-gate/plan.json here: run mutation-gate first.\n",
    ]);
});

it('exits 2 with a config it cannot read', function (): void {
    $composition = FlowCommands::composition(FlowCommands::project('"floors": 3'), ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci());

    $reported = FlowCommands::run(TestsCommand::command($composition));

    expect($reported->code)->toBe(2)
        ->and($reported->errors)->toContain('floors')
        ->and($reported->errors)->not->toContain('No run has left');
});
