<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\ReproduceCommand;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** The default branch's ledger, holding the fixture's mutants of `src/Money.php`. */
$store = static function (): ProofStoreFake {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('src/Money.php'),
        Path::of('src/Money.php'),
        Flows::mutantsOf('src/Money.php'),
        Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )));

    return $store;
};

/** `reproduce` in the flows' project, reading the default branch's ledger, with these options. */
$reproduce = static fn(ScriptedRunner $runner, string $options): FlowCommands => FlowCommands::run(
    ReproduceCommand::command(FlowCommands::composition(FlowCommands::project(), $runner, $store(), Flows::ci())),
    $options,
);

it('prints the mutant, what was recorded and what the run found now, then what the runner printed, and exits 0 where they agree', function () use ($reproduce): void {
    $reproduced = $reproduce(ScriptedRunner::fixture(), 'id=49e02f');

    expect($reproduced->code)->toBe(0)
        ->and($reproduced->errors)->toBe('')
        ->and($reproduced->output)->toBe(<<<'SAID'
            src/Money.php:16  GreaterThan  49e02fb39669
                @@ @@
                -return $amount > 100;
                +return $amount >= 100;
                Recorded: survived, by github:7/1 on main at 2026-09-29T10:00:00Z
                Now: survived
                Judged by: every test that covers it
                Explain: vendor/bin/mutation-gate explain 49e02fb39669

            What the runner printed:
            scripted: 49e02fb39669 run again

            SAID);
});

it('exits 1 where the run finds something other than the ledger recorded', function () use ($reproduce): void {
    $reproduced = $reproduce(ScriptedRunner::fixture()->killingAgain(), 'id=49e02fb39669');

    expect($reproduced->code)->toBe(1)
        ->and($reproduced->output)->toContain("Recorded: survived, by github:7/1 on main at 2026-09-29T10:00:00Z\n    Now: killed\n")
        ->and($reproduced->output)->toEndWith("scripted: 49e02fb39669 run again\nThe run found it killed where the ledger recorded it survived: it may be flaky, or its code or tests changed since.\n");
});

it('exits 2, running nothing, for a mutant no ledger read holds', function () use ($reproduce): void {
    $runner = ScriptedRunner::fixture();
    $reproduced = $reproduce($runner, 'id=abcdef');

    expect($reproduced->code)->toBe(2)
        ->and($reproduced->errors)->toBe("No ledger read holds a mutant abcdef names. Run mutation-gate on the code that has it to record it first.\n")
        ->and($runner->reproductions())->toBe([]);
});

it('exits 2 for a prefix that names several recorded mutants, listing them', function (): void {
    $store = new ProofStoreFake();
    $twins = Mutants::none();

    foreach (['abcdef000001', 'abcdef000002'] as $id) {
        $parsed = MutantId::parse($id);
        $twins = $parsed instanceof MutantId ? $twins->with(Mutant::of(
            $parsed,
            '',
            Location::of(Path::of('src/Money.php'), Line::of(16), Line::of(16)),
            Mutation::of('GreaterThan', MutatorFamily::Boundary, ''),
            MutantStatus::Survived,
            Unmeasured::duration(),
        )) : $twins;
    }

    $store->write(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('src/Money.php'),
        Path::of('src/Money.php'),
        $twins,
        Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )));

    $reproduced = FlowCommands::run(
        ReproduceCommand::command(FlowCommands::composition(FlowCommands::project(), ScriptedRunner::fixture(), $store, Flows::ci())),
        'id=abcdef',
    );

    expect([$reproduced->code, $reproduced->errors])
        ->toBe([2, "abcdef names 2 recorded mutants: abcdef000001, abcdef000002. Give more of the id.\n"]);
});

it('exits 2 for a recorded mutant the run no longer makes', function (): void {
    $gone = MutantId::parse('abcdef000001');
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('src/Money.php'),
        Path::of('src/Money.php'),
        $gone instanceof MutantId ? Mutants::of(Mutant::of(
            $gone,
            '',
            Location::of(Path::of('src/Money.php'), Line::of(16), Line::of(16)),
            Mutation::of('GreaterThan', MutatorFamily::Boundary, ''),
            MutantStatus::Survived,
            Unmeasured::duration(),
        )) : Mutants::none(),
        Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )));

    $reproduced = FlowCommands::run(
        ReproduceCommand::command(FlowCommands::composition(FlowCommands::project(), ScriptedRunner::fixture(), $store, Flows::ci())),
        'id=abcdef000001',
    );

    expect($reproduced->code)->toBe(2)
        ->and($reproduced->output)->toContain('    Now: not made. Run again, the fake made no mutant with this id.')
        ->and($reproduced->errors)->toBe("Run again, the fake made no mutant with this id. Its code, or its mutators, changed since it was recorded.\n");
});

it('exits 2 for what is not an id, and for a mutant the runner cannot run again', function () use ($reproduce): void {
    $misspelled = $reproduce(ScriptedRunner::fixture(), 'id=49E02F');
    $refused = $reproduce(ScriptedRunner::fixture()->refusingAgain('Pest is not installed.'), 'id=49e02f');

    expect([$misspelled->code, $misspelled->errors])->toBe([2, "\"49E02F\" is not a mutant id. Give the twelve lowercase hex characters every report prints, or the first six or more.\n"])
        ->and([$refused->code, $refused->errors])->toBe([2, "Pest is not installed.\n"]);
});
