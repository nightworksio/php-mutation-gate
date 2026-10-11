<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\ExplainCommand;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
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
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

/** The default branch's ledger, holding these mutants of `src/Money.php`, the fixture's where none are given. */
$store = static function (Mutant ...$mutants): ProofStoreFake {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('src/Money.php'),
        Path::of('src/Money.php'),
        $mutants === [] ? Flows::mutantsOf('src/Money.php') : Mutants::of(...$mutants),
        Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )));

    return $store;
};

/** How the command line composes a flow in a project, over this runner and these ledgers. */
$composed = static fn(ScriptedRunner $runner, ProofStoreFake $store, string $project = ''): Composition => FlowCommands::composition(
    $project === '' ? FlowCommands::project() : $project,
    $runner,
    $store,
    Flows::ci(),
);

/** A survivor of `src/Money.php`'s sixteenth line with this id and diff, as a ledger keeps it. */
$survivor = static fn(MutantId $id, string $diff = ''): Mutant => Mutant::of(
    $id,
    '',
    Location::of(Path::of('src/Money.php'), Line::of(16), Line::of(16)),
    Mutation::of('GreaterThan', MutatorFamily::Boundary, $diff),
    MutantStatus::Survived,
    Unmeasured::duration(),
);

it('explains a mutant no run in this checkout holds from its newest record, running nothing, and exits 0', function () use ($store, $composed): void {
    $runner = ScriptedRunner::fixture();
    $explained = FlowCommands::run(ExplainCommand::command($composed($runner, $store())), 'id=49e02f');

    expect($explained->code)->toBe(0)
        ->and($explained->errors)->toBe('')
        ->and($runner->reproductions())->toBe([])
        ->and($explained->output)->toBe(<<<'SAID'
            src/Money.php:16  GreaterThan  survived  49e02fb39669
                @@ @@
                -return $amount > 100;
                +return $amount >= 100;
                No test uses a value at the boundary of `$amount > 100`.
                Covered by: no test
                Unit: unknown. No run has left a plan at .mutation-gate/plan.json here: run mutation-gate first.
                History:
                    survived, by github:7/1 on main at 2026-09-29T10:00:00Z
                Reproduce: vendor/bin/mutation-gate reproduce 49e02fb39669

            SAID);
});

it('explains a mutant of the last run as its verdict judged it: its covering tests, its limit, its unit and its history', function () use ($store, $composed): void {
    $composition = $composed(ScriptedRunner::fixture(), $store());
    FlowCommands::run(RunCommand::command($composition), '--full');

    $explained = FlowCommands::run(ExplainCommand::command($composition), 'id=216c068f2e8d');

    expect($explained->code)->toBe(0)
        ->and($explained->output)->toBe(<<<'SAID'
            src/Money.php:27  Decrement  killed by timeout  216c068f2e8d
                @@ @@
                -$amount--;
                +$amount++;
                Its tests ran far past their usual time with it in place, so the timeout counts as a kill.
                Covered by:
                    tests/MoneyTest.php::it adds  unknown  0.20s
                Limit: 5.00s; its judging tests took 0.20s unmutated under it
                Unit: src/Money.php, run by the last run. Reach:
                    A full run considers every unit.
                History:
                    timed-out, by local:2026-09-30T12:00:00Z on main at 2026-09-30T12:00:00Z
                    timed-out, by github:7/1 on main at 2026-09-29T10:00:00Z
                Reproduce: vendor/bin/mutation-gate reproduce 216c068f2e8d

            SAID);
});

it('takes a held unit\'s mutant as judged by the holding tests that run it, which here are the tests that cover it', function () use ($store, $composed): void {
    $composition = $composed(ScriptedRunner::fixture(), $store());
    FlowCommands::run(RunCommand::command($composition), '--full');

    $explained = FlowCommands::run(ExplainCommand::command($composition), 'id=8705b7dc7d27');

    expect($explained->output)->toContain(<<<'SAID'
            Covered by:
                tests/HeldTest.php::it doubles  passed
            Unit: src/Held.php, run by the last run. Reach:
        SAID);
});

it('prints the same as JSON with --format=json, each mutant as the json report gives it, within its schema', function () use ($store, $composed): void {
    $composition = $composed(ScriptedRunner::fixture(), $store());
    FlowCommands::run(RunCommand::command($composition), '--full');

    $explained = FlowCommands::run(ExplainCommand::command($composition), 'id=216c068f2e8d --format=json');
    $json = $explained->output;
    $at = static fn(string|int ...$keys): mixed => Decoded::at($json, 'mutants', 0, ...$keys);

    expect($explained->code)->toBe(0)
        ->and(Schema::errors($json, Schema::at('resources/explain.schema.json')))->toBe([])
        ->and($at('mutant', 'id'))->toBe('216c068f2e8d')
        ->and($at('mutant', 'judgement'))->toBe('killed-by-timeout')
        ->and($at('mutant', 'coveredBy'))->toBe([0])
        ->and($at('mutant', 'limit'))->toBe(5.0)
        ->and($at('judgingSeconds'))->toBe(0.2)
        ->and($at('tests'))->toBe([[
            'id' => 'MoneyTest::adds',
            'name' => 'tests/MoneyTest.php::it adds',
            'file' => 'tests/MoneyTest.php',
            'seconds' => 0.2,
            'outcome' => 'unknown',
        ]])
        ->and($at('unit'))->toBe(['path' => 'src/Money.php', 'origin' => 'run', 'reach' => ['A full run considers every unit.']])
        ->and($at('history'))->toBe([
            ['status' => 'timed-out', 'run' => 'local:2026-09-30T12:00:00Z', 'scope' => 'refs/heads/main', 'at' => '2026-09-30T12:00:00Z'],
            ['status' => 'timed-out', 'run' => 'github:7/1', 'scope' => 'refs/heads/main', 'at' => '2026-09-29T10:00:00Z'],
        ]);
});

it('says why a last run whose shards left no results cannot say how it took a mutant', function () use ($store, $composed): void {
    $composition = $composed(ScriptedRunner::fixture(), $store());
    FlowCommands::run(PlanCommand::command($composition), '--shards=1');

    $explained = FlowCommands::run(ExplainCommand::command($composition), 'id=49e02f');

    expect($explained->code)->toBe(0)
        ->and($explained->output)->toContain("\n    Unit: unknown. Shard 1 (")
        ->and($explained->output)->toContain('left no result at .mutation-gate/results/1.json, so its mutants cannot be judged.');
});

it('says in the JSON why the last run cannot say how it took a mutant it does not hold', function () use ($store, $composed): void {
    $json = FlowCommands::run(ExplainCommand::command($composed(ScriptedRunner::fixture(), $store())), 'id=49e02f --format=json')->output;

    expect(Decoded::at($json, 'mutants', 0, 'unit'))->toBe(['unknown' => 'No run has left a plan at .mutation-gate/plan.json here: run mutation-gate first.'])
        ->and(Decoded::at($json, 'mutants', 0, 'tests'))->toBe([])
        ->and(Decoded::at($json, 'mutants', 0, 'judgingSeconds'))->toBeNull();
});

it('explains a cluster of the last run by its id: its heading, hint and stub, then each member', function () use ($composed): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    $comparison = static fn(string $mutator, string $added): Mutant => Verdicts::mutant(
        'src/Money.php:7',
        $mutator,
        MutatorFamily::Boundary,
        Verdicts::diff('if ($amount < $limit) {', $added),
    );
    $runner = ScriptedRunner::fixture()->answering(Mutants::of(
        $comparison('LessThan', 'if ($amount <= $limit) {'),
        $comparison('LessThanNegotiation', 'if ($amount > $limit) {'),
    ), 0);
    $composition = $composed($runner, new ProofStoreFake(), $project);
    $ran = FlowCommands::run(RunCommand::command($composition), '--full');
    $id = preg_match('/\b(k[0-9a-f]{11})\b/', $ran->output, $found) === 1 ? $found[1] : '';

    $explained = FlowCommands::run(ExplainCommand::command($composition), sprintf('id=%s', $id));
    $json = FlowCommands::run(ExplainCommand::command($composition), sprintf('id=%s --format=json', $id))->output;
    $other = FlowCommands::run(ExplainCommand::command($composition), 'id=k0123456789a');
    $members = Decoded::at($json, 'cluster', 'members');
    $count = is_array($members) ? count($members) : 0;
    $explainedIds = [];

    for ($index = 0; $index < $count; ++$index) {
        $explainedIds[] = Decoded::at($json, 'mutants', $index, 'mutant', 'id');
    }

    expect($explained->code)->toBe(0)
        ->and($explained->output)->toStartWith(sprintf("src/Money.php:7  %d survivors, one expression  %s\n", $count, $id))
        ->and($explained->output)->toContain(sprintf("    Stub: vendor/bin/mutation-gate stub %s\n\nsrc/Money.php:7  LessThan  survived", $id))
        ->and(substr_count($explained->output, 'Reproduce: vendor/bin/mutation-gate reproduce'))->toBe($count)
        ->and(Decoded::at($json, 'cluster', 'id'))->toBe($id)
        ->and($explainedIds)->toBe($members)
        ->and([$other->code, $other->output])->toBe([2, '']);
});

it('exits 2 for a cluster id the last run names no cluster by, or where there is no last run to find clusters in', function () use ($store, $composed): void {
    $composition = $composed(ScriptedRunner::fixture(), $store());
    $unread = FlowCommands::run(ExplainCommand::command($composition), 'id=k0123456789a');
    FlowCommands::run(RunCommand::command($composition), '--full');
    $unfound = FlowCommands::run(ExplainCommand::command($composition), 'id=k0123456789a');
    $misspelled = FlowCommands::run(ExplainCommand::command($composition), 'id=k0123');

    expect([$unread->code, $unread->errors])
        ->toBe([2, "Clusters are found by the last run, which cannot be read. No run has left a plan at .mutation-gate/plan.json here: run mutation-gate first.\n"])
        ->and([$unfound->code, $unfound->errors])
        ->toBe([2, "The last run found no cluster k0123456789a: clusters change as survivors do, so give one it printed.\n"])
        ->and([$misspelled->code, $misspelled->errors])
        ->toBe([2, "\"k0123\" is not a cluster id, which is \"k\" and eleven lowercase hex characters, as every report prints it.\n"]);
});

it('explains an id of twelve hex characters as a mutant, a letter first or not', function () use ($store, $composed, $survivor): void {
    $id = MutantId::parse('c0ffee000001');
    $composition = $composed(ScriptedRunner::fixture(), $id instanceof MutantId ? $store($survivor($id)) : $store());
    FlowCommands::run(RunCommand::command($composition), '--full');

    $explained = FlowCommands::run(ExplainCommand::command($composition), 'id=c0ffee000001');

    expect($explained->code)->toBe(0)
        ->and($explained->output)->toStartWith("src/Money.php:16  GreaterThan  survived  c0ffee000001\n")
        ->and($explained->output)->toContain("\n    Unit: unknown. It is not among the mutants of the last run, so this is its newest record.\n");
});

it('exits 2 for a mutant neither a ledger nor the last run holds', function () use ($composed): void {
    $explained = FlowCommands::run(ExplainCommand::command($composed(ScriptedRunner::fixture(), new ProofStoreFake())), 'id=abcdef');

    expect([$explained->code, $explained->output, $explained->errors])
        ->toBe([2, '', "No ledger read holds a mutant abcdef names. Run mutation-gate on the code that has it to record it first.\n"]);
});

it('exits 2 for a prefix that names several mutants, in the ledgers and the last run together', function () use ($store, $composed, $survivor): void {
    $twin = MutantId::parse('49e02f000001');
    $composition = $composed(ScriptedRunner::fixture(), $twin instanceof MutantId ? $store($survivor($twin)) : $store());
    $recorded = FlowCommands::run(ExplainCommand::command($composition), 'id=49e02f000001');
    FlowCommands::run(RunCommand::command($composition), '--full');

    $ambiguous = FlowCommands::run(ExplainCommand::command($composition), 'id=49e02f');

    expect($recorded->code)->toBe(0)
        ->and([$ambiguous->code, $ambiguous->errors])
        ->toBe([2, "49e02f names 2 recorded mutants: 49e02fb39669, 49e02f000001. Give more of the id.\n"]);
});

it('exits 2 for a prefix that names several mutants of the last run, where no ledger holds either', function () use ($composed, $survivor): void {
    $twins = [];

    foreach (['abc123000001', 'abc123000002'] as $twin) {
        $id = MutantId::parse($twin);
        $twins = $id instanceof MutantId
            ? [...$twins, $survivor($id, Verdicts::diff('return $amount > 100;', 'return $amount >= 100;'))]
            : $twins;
    }

    $project = FlowCommands::project();
    $runner = ScriptedRunner::fixture()->answering(Mutants::of(...$twins), 0);
    FlowCommands::run(RunCommand::command($composed($runner, new ProofStoreFake(), $project)), '--full');

    $ambiguous = FlowCommands::run(ExplainCommand::command($composed($runner, new ProofStoreFake(), $project)), 'id=abc123');

    expect([$ambiguous->code, $ambiguous->errors])
        ->toBe([2, "abc123 names 2 recorded mutants: abc123000001, abc123000002. Give more of the id.\n"]);
});

it('exits 2 for what is no id, and for a form it does not write', function () use ($store, $composed): void {
    $composition = $composed(ScriptedRunner::fixture(), $store());
    $mutant = FlowCommands::run(ExplainCommand::command($composition), 'id=49E02F');
    $form = FlowCommands::run(ExplainCommand::command($composition), 'id=49e02f --format=yaml');

    expect([$mutant->code, $mutant->errors])->toBe([2, "\"49E02F\" is not a mutant id. Give the twelve lowercase hex characters every report prints, or the first six or more.\n"])
        ->and([$form->code, $form->output, $form->errors])->toBe([2, '', "--format is yaml; explain writes text or json.\n"]);
});
