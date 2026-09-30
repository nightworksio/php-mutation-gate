<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\PreCommitCommand;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** `pre-commit` in a project over these trees, with `main` proving each file of the fixture. */
$preCommit = static function (string $project, Trees $trees): FlowCommands {
    $store = new ProofStoreFake();
    $ledger = Ledger::empty();

    foreach (['src/Money.php', 'src/Held.php'] as $file) {
        $ledger = $ledger->withProof(Proof::of(
            Digest::sha256Of($file),
            Path::of($file),
            Flows::mutantsOf($file),
            Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('main')),
        ));
    }

    $store->write(Scope::branch('main'), $ledger);

    return FlowCommands::run(PreCommitCommand::command(FlowCommands::over(
        $trees,
        $project,
        ScriptedRunner::fixture(),
        $store,
        Flows::ci(),
        Variables::of([]),
    )));
};

it('prints each reached tree\'s score change and exits 0', function () use ($preCommit): void {
    $printed = $preCommit(FlowCommands::project(), Flows::trees());

    expect([$printed->code, $printed->output, $printed->errors])->toBe([
        0,
        "src scores 40.00%, below its floor of 50.00%. That is ±0.00 against the base.\n"
        . "2 units unjudged since your last run: mutation-gate\n",
        '',
    ]);
});

it('exits 0 when it cannot say, saying why', function () use ($preCommit): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'mutation-gate.baseline.json', '{"format": 9}');
    $printed = $preCommit($project, Flows::trees());

    expect([$printed->code, $printed->output])->toBe([0, ''])
        ->and($printed->errors)->toContain('mutation-gate.baseline.json');
});

it('exits 0 on a config it cannot read, saying why', function () use ($preCommit): void {
    $printed = $preCommit(FlowCommands::project('"floors": 5'), Flows::trees());

    expect([$printed->code, $printed->output])->toBe([0, ''])
        ->and($printed->errors)->not->toBe('');
});
