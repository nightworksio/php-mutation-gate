<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\BaselineCommand;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
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

/** A proof of a file of the fixture, with the mutants the fake runner finds in it, or with none. */
$proofOf = static fn(string $file, bool $mutants): Proof => Proof::of(
    Digest::sha256Of($file),
    Path::of($file),
    $mutants ? Flows::mutantsOf($file) : Mutants::none(),
    Run::of('local', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
);

/** The default branch's ledger, holding a proof of each of these files of the fixture. */
$ledgers = static function (string ...$files) use ($proofOf): ProofStoreFake {
    $store = new ProofStoreFake();
    $ledger = Ledger::empty();

    foreach ($files as $file) {
        $ledger = $ledger->withProof($proofOf($file, mutants: true));
    }

    $store->write(Scope::branch('main'), $ledger);

    return $store;
};

$root = static fn(): Package => Package::at(Path::root());

/** `src/Money.php`, which scores 50 and is held to this floor, and `src/Held.php`, which scores 0. */
$trees = static fn(float $floor): Trees => Trees::of(
    Tree::at(Path::of('src/Money.php'), Floor::of($floor), $root()),
    Tree::at(Path::of('src/Held.php'), Floor::of(0.5), $root()),
);

/**
 * `baseline` in a project over these trees, reading these ledgers.
 */
$baseline = static fn(
    string $project,
    Trees $trees,
    ProofStoreFake $store,
    string $input,
    ScriptedRunner $runner,
): FlowCommands => FlowCommands::run(
    BaselineCommand::command(FlowCommands::over($trees, $project, $runner, $store, Flows::ci(), Variables::of([]))),
    $input,
);

it('shows every tree\'s committed floor beside its last measured score', function () use (
    $baseline,
    $ledgers,
    $trees,
): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'mutation-gate.baseline.json', BaselineFile::encode(
        Baseline::of(Entry::of(Path::of('src/Money.php'), Floor::of(42.5))),
    ));

    $shown = $baseline($project, $trees(30), $ledgers('src/Money.php', 'src/Held.php'), '', ScriptedRunner::fixture());

    expect($shown->code)->toBe(0)
        ->and($shown->errors)->toBe('')
        ->and($shown->output)->toBe("src/Money.php: floor 42.5, measured 50\nsrc/Held.php: floor none, measured 0\n");
});

it('shows a tree with nothing to mutate, and one not measured yet', function () use ($baseline, $proofOf): void {
    $project = FlowCommands::project();
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($proofOf('src/Money.php', mutants: false)));
    $trees = Trees::of(
        Tree::at(Path::of('src/Money.php'), Floor::of(50), Package::at(Path::root())),
        Tree::at(Path::of('src/Held.php'), Floor::of(50), Package::at(Path::root())),
    );

    $shown = $baseline($project, $trees, $store, '', ScriptedRunner::fixture());

    expect($shown->output)->toBe(<<<'SAID'
        src/Money.php: floor none, measured nothing to mutate
        src/Held.php: floor none, not measured: a unit of it has no result yet. Run mutation-gate to measure it.

        SAID);
});

it('writes every floor its last measured score raised with --write, and says which lines to commit', function () use (
    $baseline,
    $ledgers,
    $trees,
): void {
    $project = FlowCommands::project();

    $written = $baseline(
        $project,
        $trees(30),
        $ledgers('src/Money.php', 'src/Held.php'),
        '--write',
        ScriptedRunner::fixture(),
    );

    expect($written->code)->toBe(0)
        ->and($written->output)->toBe(<<<'SAID'
            Raised the floors in mutation-gate.baseline.json. Commit it:
              src/Money.php: 50, was none

            SAID)
        ->and(file_get_contents(sprintf('%s/mutation-gate.baseline.json', $project)))
        ->toBe(BaselineFile::encode(Baseline::of(Entry::of(Path::of('src/Money.php'), Floor::of(50)))));
});

it('writes nothing with --write where no floor rose', function () use ($baseline, $ledgers, $trees): void {
    $project = FlowCommands::project();

    $written = $baseline(
        $project,
        $trees(60),
        $ledgers('src/Money.php', 'src/Held.php'),
        '--write',
        ScriptedRunner::fixture(),
    );

    expect($written->output)->toBe("No floor in mutation-gate.baseline.json rose.\n")
        ->and(is_file(sprintf('%s/mutation-gate.baseline.json', $project)))->toBeFalse();
});

it('cannot write with --write a baseline whose directory is a file', function () use (
    $baseline,
    $ledgers,
    $trees,
): void {
    $project = FlowCommands::project('"baseline": {"path": "floors/baseline.json"}');
    Scratch::write($project, 'floors', 'a file where the baseline\'s directory should be');

    $written = $baseline(
        $project,
        $trees(30),
        $ledgers('src/Money.php', 'src/Held.php'),
        '--write',
        ScriptedRunner::fixture(),
    );

    expect($written->code)->toBe(2)
        ->and($written->output)->toBe('')
        ->and($written->errors)->toBe(sprintf("%s/floors/baseline.json could not be written.\n", $project));
});

it('cannot show a baseline it cannot read', function () use ($baseline, $ledgers, $trees): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'mutation-gate.baseline.json', 'not a baseline');

    $shown = $baseline($project, $trees(30), $ledgers(), '', ScriptedRunner::fixture());
    $refused = BaselineFile::decode('not a baseline', Path::of('mutation-gate.baseline.json'));

    expect($shown->code)->toBe(2)
        ->and($shown->output)->toBe('')
        ->and($refused)->toBeInstanceOf(CannotJudge::class)
        ->and($shown->errors)->toBe(sprintf("%s\n", $refused instanceof CannotJudge ? $refused->why() : ''));
});

it('cannot show what it cannot measure', function () use ($baseline, $ledgers, $trees): void {
    $shown = $baseline(
        FlowCommands::project(),
        $trees(30),
        $ledgers(),
        '',
        ScriptedRunner::fixture()->unlisted('PHPUnit could not list the groups.'),
    );

    expect($shown->code)->toBe(2)
        ->and($shown->errors)->toBe("PHPUnit could not list the groups.\n");
});

it('cannot show with a config it cannot read', function () use ($baseline, $ledgers, $trees): void {
    $shown = $baseline(
        FlowCommands::project('"shards": {"max": 0}'),
        $trees(30),
        $ledgers(),
        '',
        ScriptedRunner::fixture(),
    );

    expect($shown->code)->toBe(2)
        ->and($shown->errors)->toContain('shards.max:');
});

it('offers --write as a flag', function () use ($trees): void {
    $command = BaselineCommand::command(FlowCommands::over(
        $trees(30),
        FlowCommands::project(),
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of([]),
    ));

    expect($command->getDefinition()->getOption('write')->acceptValue())->toBeFalse();
});
