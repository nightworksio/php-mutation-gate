<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Survivors;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Recheck\Gone;
use NightWorksIO\MutationGate\Core\Recheck\NoRecheck;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

$store = static fn(string ...$files): ProofStoreFake => Rechecks::store(...$files);

/** The survivors re-checked, with what each became: its id and its status now, or gone. */
$outcomes = static fn(Rechecked|NoRecheck|CannotJudge $rechecked): array => $rechecked instanceof Rechecked
    ? array_map(static fn(Recheck $recheck): array => [
        $recheck->before()->mutant()->id()->value(),
        $recheck->now() instanceof JudgedMutant ? $recheck->now()->mutant()->status() : $recheck->now(),
    ], [...$rechecked])
    : [$rechecked];

it('runs the survivors and counted uncovered mutants of the branch\'s last run again, one run per file of their mutators alone', function () use ($store, $outcomes): void {
    $runner = ScriptedRunner::fixture();
    $ran = Rechecks::ports('feature', $store('src/Money.php', 'src/Held.php'), $runner);
    $plan = Rechecks::plan($ran, Flows::settings(), Mode::full());
    $before = count($runner->requests());

    $rechecked = Rechecks::of(Flows::adapters(Flows::project(), [], ...$ran), Flows::settings(), $plan);
    $asked = array_slice($runner->requests(), $before);

    expect($outcomes($rechecked))->toEqual([
        ['8705b7dc7d27', MutantStatus::Survived],
        ['49e02fb39669', MutantStatus::Survived],
        ['95e61bd8bf62', MutantStatus::Uncovered],
    ])
        ->and(array_map(static fn(MutationRequest $request): array => [
            array_map(static fn(Path $file): string => $file->value(), [...$request->files()]),
            $request->narrowing()->mutators(),
            $request->judgedBy(),
        ], $asked))->toEqual([
            [['src/Held.php'], Mutators::named('Plus'), Group::named('holds:src/Held.php')],
            [['src/Money.php'], Mutators::named('GreaterThan', 'Minus'), WholeSuite::tests()],
        ]);
});

it('re-checks each file on the maps the plan handed on, a mutant on each core, so it runs no suite for coverage', function () use ($store): void {
    $runner = ScriptedRunner::fixture()->behaving(RunnerBehaviour::standard()->runningPerCore());
    $ran = Rechecks::ports('feature', $store('src/Money.php', 'src/Held.php'), $runner);
    $plan = Rechecks::plan($ran, Flows::settings(), Mode::full());
    $before = count($runner->requests());
    $adapters = Flows::adapters(Flows::project(), [], ...$ran);

    Rechecks::of($adapters, Flows::settings(), $plan);
    $asked = array_slice($runner->requests(), $before);

    expect($asked)->toHaveCount(2)
        ->and(array_map(static fn(MutationRequest $request): Handed|string => $request->coverage() instanceof Handed
            ? $request->coverage()
            : 'fresh', $asked))
        ->each->toEqual(Handed::maps(Workspace::verdictCoverage(), Workspace::coverage()))
        ->and(array_map(static fn(MutationRequest $request): Pool => $request->pool(), $asked))
        ->each->toEqual(Pool::of($adapters->cores, Flows::settings()->runner()->workers()))
        ->and($adapters->cores)->not->toEqual(ProcessCount::single());
});

it('says which were killed, and which the run made again by no mutant', function () use ($store, $outcomes): void {
    $again = [];

    foreach (Flows::mutantsOf('src/Money.php') as $mutant) {
        $again = match ($mutant->id()->value()) {
            '49e02fb39669' => [...$again, Mutant::of(
                $mutant->id(),
                $mutant->nativeId(),
                $mutant->location(),
                $mutant->mutation(),
                MutantStatus::Killed,
                $mutant->duration(),
            )],
            '95e61bd8bf62' => $again,
            default => [...$again, $mutant],
        };
    }

    $ran = Rechecks::ports('feature', $store('src/Money.php'), ScriptedRunner::fixture()->answering(Mutants::of(...$again), 0));

    expect($outcomes(Rechecks::in($ran, Flows::settings())))->toEqual([
        ['49e02fb39669', MutantStatus::Killed],
        ['95e61bd8bf62', Gone::value()],
    ]);
});

it('re-checks the survivors on the change\'s lines first', function () use ($store, $outcomes): void {
    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(21)))),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES],
    );
    $ran = Rechecks::ports('feature', $store('src/Money.php', 'src/Held.php'), ScriptedRunner::fixture(), $checkout);
    $plan = Rechecks::plan($ran, Flows::settings(), Mode::since('base'));

    expect($outcomes(Rechecks::of(Flows::adapters(Flows::project(), [], ...$ran), Flows::settings(), $plan)))->toEqual([
        ['95e61bd8bf62', MutantStatus::Uncovered],
        ['49e02fb39669', MutantStatus::Survived],
    ]);
});

it('re-checks the survivors of a unit a proof covers, as of one the plan runs', function () use ($store, $outcomes): void {
    $full = Rechecks::plan(Rechecks::ports('feature', $store(), ScriptedRunner::fixture()), Flows::settings(), Mode::full());
    $key = $full instanceof Plan ? $full->keys()->keyOf(Path::of('src/Money.php')) : $full;
    $proofs = $store();
    $proofs->write(Scope::branch('feature'), Ledger::empty()->withProof(Proof::of(
        $key instanceof Digest ? $key : Digest::of('none'),
        Path::of('src/Money.php'),
        Flows::mutantsOf('src/Money.php'),
        Run::of('github:earlier', Moment::at('2026-09-29T10:00:00Z'), $full instanceof Plan ? $full->base() : Digest::of('none')),
    )));
    $ran = Rechecks::ports('feature', $proofs, ScriptedRunner::fixture());
    $plan = Rechecks::plan($ran, Flows::settings(), Mode::full());

    expect($plan instanceof Plan ? $plan->considered()->proved()->count() : $plan)->toBe(1)
        ->and(array_column($outcomes(Rechecks::of(Flows::adapters(Flows::project(), [], ...$ran), Flows::settings(), $plan)), 0))
        ->toBe(['49e02fb39669', '95e61bd8bf62']);
});

it('takes no more survivors than survivorsFirst.max, and leaves out the ignored ones', function () use ($store, $outcomes): void {
    $ran = Rechecks::ports('feature', $store('src/Money.php', 'src/Held.php'), ScriptedRunner::fixture());

    expect($outcomes(Rechecks::in($ran, Flows::settings(Survivors::firstAtMost(1)))))
        ->toEqual([['8705b7dc7d27', MutantStatus::Survived]])
        ->and(array_column($outcomes(Rechecks::in($ran, Flows::settings(Ignore::mutant('49e02fb39669', 'Both branches return the same', '2099-01-01')))), 0))
        ->toBe(['8705b7dc7d27', '95e61bd8bf62']);
});

it('re-checks only the security mutators\' survivors where the plan was made for them alone', function () use ($store, $outcomes): void {
    $ran = Rechecks::ports('feature', $store('src/Money.php', 'src/Held.php'), ScriptedRunner::fixture());
    $secured = Flows::adapters(Flows::project(), [], ...[...$ran, NamedMutators::of('Plus')])->securityOnly();
    $plan = Rechecks::plan($ran, Flows::settings(), Mode::full());

    expect($secured instanceof CannotJudge ? $secured : array_column($outcomes(Rechecks::of($secured, Flows::settings(), $plan)), 0))
        ->toBe(['8705b7dc7d27']);
});

it('re-checks nothing, and says why, where it is off, on the default branch, or the branch has no earlier survivor', function (
    string $branch,
    Closure $proofs,
    Settings $chosen,
    NoRecheck $why,
): void {
    $runner = ScriptedRunner::fixture();
    $store = $proofs();
    $ran = Rechecks::ports($branch, $store instanceof ProofStoreFake ? $store : new ProofStoreFake(), $runner);
    $plan = Rechecks::plan($ran, $chosen, Mode::full());
    $before = count($runner->requests());

    expect(Rechecks::of(Flows::adapters(Flows::project(), [], ...$ran), $chosen, $plan))->toEqual($why)
        ->and(count($runner->requests()))->toBe($before);
})->with([
    'off' => ['feature', fn(): ProofStoreFake => Rechecks::store('src/Money.php'), fn(): Settings => Flows::settings(Survivors::firstAtMost(0)), fn(): NoRecheck => NoRecheck::off()],
    'the default branch' => ['main', fn(): ProofStoreFake => Rechecks::store(), fn(): Settings => Flows::settings(), fn(): NoRecheck => NoRecheck::onTheDefaultBranch()],
    'a first push' => ['feature', fn(): ProofStoreFake => Rechecks::store(), fn(): Settings => Flows::settings(), fn(): NoRecheck => NoRecheck::noEarlierRun()],
    'everything ignored' => [
        'feature',
        fn(): ProofStoreFake => Rechecks::store('src/Held.php'),
        fn(): Settings => Flows::settings(Ignore::mutant('8705b7dc7d27', 'Doubling is checked elsewhere', '2099-01-01')),
        fn(): NoRecheck => NoRecheck::noneLeft(),
    ],
]);

it('re-checks nothing, and says why, where the run has no ref of its own', function () use ($store): void {
    $ran = [$store('src/Money.php'), new CiPlanFake(RunOn::detached(Scope::branch('main'))), ScriptedRunner::fixture()];

    expect(Rechecks::in($ran, Flows::settings()))->toEqual(NoRecheck::withoutAScope());
});

it('cannot re-check where the trees cannot be read, or the runner cannot run the file again', function () use ($store): void {
    $plan = Rechecks::plan(Rechecks::ports('feature', $store('src/Money.php'), ScriptedRunner::fixture()), Flows::settings(), Mode::full());
    $unread = Rechecks::ports('feature', $store('src/Money.php'), new TreeSourceFake(CannotJudge::because('No tree is declared.')));
    $refused = Rechecks::ports('feature', $store('src/Money.php'), ScriptedRunner::fixture()->refusing('Pest is not installed.'));

    expect(Rechecks::of(Flows::adapters(Flows::project(), [], ...$unread), Flows::settings(), $plan))
        ->toEqual(CannotJudge::because('No tree is declared.'))
        ->and(Rechecks::of(Flows::adapters(Flows::project(), [], ...$refused), Flows::settings(), $plan))
        ->toEqual(CannotJudge::because('Pest is not installed.'));
});
