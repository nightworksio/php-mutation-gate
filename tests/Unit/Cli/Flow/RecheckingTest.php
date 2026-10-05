<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Rechecking;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Survivors;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Recheck\Gone;
use NightWorksIO\MutationGate\Core\Recheck\NoRecheck;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** A store with the default branch's ledger, and the feature branch's where it has these files. */
function recheckingStore(string ...$files): ProofStoreFake
{
    $ledger = static function (string ...$proved): Ledger {
        $ledger = Ledger::empty();

        foreach ($proved as $file) {
            $ledger = $ledger->withProof(Proof::of(
                Digest::sha256Of(sprintf('%s earlier', $file)),
                Path::of($file),
                Flows::mutantsOf($file),
                Run::of('github:earlier', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
            ));
        }

        return $ledger;
    };
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $ledger('src/Money.php', 'src/Held.php'));

    if ($files !== []) {
        $store->write(Scope::branch('feature'), $ledger(...$files));
    }

    return $store;
}

$store = static fn(string ...$files): ProofStoreFake => recheckingStore(...$files);

/**
 * The ports of a run on this branch, the default branch being main.
 *
 * @return list<object>
 */
function recheckingPorts(string $branch, ProofStoreFake $store, object ...$more): array
{
    return [$store, new CiPlanFake(RunOn::at(Scope::branch($branch), Scope::branch('main'))), ...array_values($more)];
}

/**
 * The plan these ports' run makes, as `plan` makes it in this mode, in one shard.
 *
 * @param list<object> $ports
 */
function recheckingPlan(array $ports, Settings $settings, Mode $mode): Plan|CannotJudge
{
    $made = new Planning(Flows::adapters(Flows::project(), [], ...$ports), $settings, Flows::setup())
        ->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller);

    return $made instanceof PlanMade ? $made->plan() : $made;
}

/** What these adapters' run re-checks before the plan's shards; why there is no plan, where there is none. */
function recheckingOf(Adapters $adapters, Settings $settings, Plan|CannotJudge $plan): Rechecked|NoRecheck|CannotJudge
{
    return $plan instanceof Plan ? new Rechecking($adapters, $settings, Flows::setup())->recheck($plan) : $plan;
}

/**
 * What the run on these ports re-checks of a plan of every unit, made in full.
 *
 * @param list<object> $ports
 */
function recheckedIn(array $ports, Settings $settings): Rechecked|NoRecheck|CannotJudge
{
    return recheckingOf(Flows::adapters(Flows::project(), [], ...$ports), $settings, recheckingPlan($ports, $settings, Mode::full()));
}

/** The survivors re-checked, with what each became: its id and its status now, or gone. */
$outcomes = static fn(Rechecked|NoRecheck|CannotJudge $rechecked): array => $rechecked instanceof Rechecked
    ? array_map(static fn(Recheck $recheck): array => [
        $recheck->before()->mutant()->id()->value(),
        $recheck->now() instanceof JudgedMutant ? $recheck->now()->mutant()->status() : $recheck->now(),
    ], [...$rechecked])
    : [$rechecked];

it('runs the survivors and counted uncovered mutants of the branch\'s last run again, one run per file of their mutators alone', function () use ($store, $outcomes): void {
    $runner = ScriptedRunner::fixture();
    $ran = recheckingPorts('feature', $store('src/Money.php', 'src/Held.php'), $runner);
    $plan = recheckingPlan($ran, Flows::settings(), Mode::full());
    $before = count($runner->requests());

    $rechecked = recheckingOf(Flows::adapters(Flows::project(), [], ...$ran), Flows::settings(), $plan);
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

    $ran = recheckingPorts('feature', $store('src/Money.php'), ScriptedRunner::fixture()->answering(Mutants::of(...$again), 0));

    expect($outcomes(recheckedIn($ran, Flows::settings())))->toEqual([
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
    $ran = recheckingPorts('feature', $store('src/Money.php', 'src/Held.php'), ScriptedRunner::fixture(), $checkout);
    $plan = recheckingPlan($ran, Flows::settings(), Mode::since('base'));

    expect($outcomes(recheckingOf(Flows::adapters(Flows::project(), [], ...$ran), Flows::settings(), $plan)))->toEqual([
        ['95e61bd8bf62', MutantStatus::Uncovered],
        ['49e02fb39669', MutantStatus::Survived],
    ]);
});

it('re-checks the survivors of a unit a proof covers, as of one the plan runs', function () use ($store, $outcomes): void {
    $full = recheckingPlan(recheckingPorts('feature', $store(), ScriptedRunner::fixture()), Flows::settings(), Mode::full());
    $key = $full instanceof Plan ? $full->keys()->keyOf(Path::of('src/Money.php')) : $full;
    $proofs = $store();
    $proofs->write(Scope::branch('feature'), Ledger::empty()->withProof(Proof::of(
        $key instanceof Digest ? $key : Digest::of('none'),
        Path::of('src/Money.php'),
        Flows::mutantsOf('src/Money.php'),
        Run::of('github:earlier', Moment::at('2026-09-29T10:00:00Z'), $full instanceof Plan ? $full->base() : Digest::of('none')),
    )));
    $ran = recheckingPorts('feature', $proofs, ScriptedRunner::fixture());
    $plan = recheckingPlan($ran, Flows::settings(), Mode::full());

    expect($plan instanceof Plan ? $plan->considered()->proved()->count() : $plan)->toBe(1)
        ->and(array_column($outcomes(recheckingOf(Flows::adapters(Flows::project(), [], ...$ran), Flows::settings(), $plan)), 0))
        ->toBe(['49e02fb39669', '95e61bd8bf62']);
});

it('takes no more survivors than survivorsFirst.max, and leaves out the ignored ones', function () use ($store, $outcomes): void {
    $ran = recheckingPorts('feature', $store('src/Money.php', 'src/Held.php'), ScriptedRunner::fixture());

    expect($outcomes(recheckedIn($ran, Flows::settings(Survivors::firstAtMost(1)))))
        ->toEqual([['8705b7dc7d27', MutantStatus::Survived]])
        ->and(array_column($outcomes(recheckedIn($ran, Flows::settings(Ignore::mutant('49e02fb39669', 'Both branches return the same', '2099-01-01')))), 0))
        ->toBe(['8705b7dc7d27', '95e61bd8bf62']);
});

it('leaves out a survivor proven equivalent to its original', function () use ($store, $outcomes): void {
    $equivalent = Checkable::inPlace(Contents::of("<?php\n\nfinal  class Money\n{\n}\n"));
    $ran = recheckingPorts('feature', $store('src/Money.php'), ScriptedRunner::fixture()->checking($equivalent));

    expect(array_column($outcomes(recheckedIn($ran, Flows::settings())), 0))->toBe(['95e61bd8bf62']);
});

it('re-checks only the security mutators\' survivors where the plan was made for them alone', function () use ($store, $outcomes): void {
    $ran = recheckingPorts('feature', $store('src/Money.php', 'src/Held.php'), ScriptedRunner::fixture());
    $secured = Flows::adapters(Flows::project(), [], ...[...$ran, NamedMutators::of('Plus')])->securityOnly();
    $plan = recheckingPlan($ran, Flows::settings(), Mode::full());

    expect($secured instanceof CannotJudge ? $secured : array_column($outcomes(recheckingOf($secured, Flows::settings(), $plan)), 0))
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
    $ran = recheckingPorts($branch, $store instanceof ProofStoreFake ? $store : new ProofStoreFake(), $runner);
    $plan = recheckingPlan($ran, $chosen, Mode::full());
    $before = count($runner->requests());

    expect(recheckingOf(Flows::adapters(Flows::project(), [], ...$ran), $chosen, $plan))->toEqual($why)
        ->and(count($runner->requests()))->toBe($before);
})->with([
    'off' => ['feature', fn(): ProofStoreFake => recheckingStore('src/Money.php'), Flows::settings(Survivors::firstAtMost(0)), NoRecheck::off()],
    'the default branch' => ['main', fn(): ProofStoreFake => recheckingStore(), Flows::settings(), NoRecheck::onTheDefaultBranch()],
    'a first push' => ['feature', fn(): ProofStoreFake => recheckingStore(), Flows::settings(), NoRecheck::noEarlierRun()],
    'everything ignored' => [
        'feature',
        fn(): ProofStoreFake => recheckingStore('src/Held.php'),
        Flows::settings(Ignore::mutant('8705b7dc7d27', 'Doubling is checked elsewhere', '2099-01-01')),
        NoRecheck::noneLeft(),
    ],
]);

it('re-checks nothing, and says why, where the run has no ref of its own', function () use ($store): void {
    $ran = [$store('src/Money.php'), new CiPlanFake(RunOn::detached(Scope::branch('main'))), ScriptedRunner::fixture()];

    expect(recheckedIn($ran, Flows::settings()))->toEqual(NoRecheck::withoutAScope());
});

it('cannot re-check where the trees cannot be read, or the runner cannot run the file again', function () use ($store): void {
    $plan = recheckingPlan(recheckingPorts('feature', $store('src/Money.php'), ScriptedRunner::fixture()), Flows::settings(), Mode::full());
    $unread = recheckingPorts('feature', $store('src/Money.php'), new TreeSourceFake(CannotJudge::because('No tree is declared.')));
    $refused = recheckingPorts('feature', $store('src/Money.php'), ScriptedRunner::fixture()->refusing('Pest is not installed.'));

    expect(recheckingOf(Flows::adapters(Flows::project(), [], ...$unread), Flows::settings(), $plan))
        ->toEqual(CannotJudge::because('No tree is declared.'))
        ->and(recheckingOf(Flows::adapters(Flows::project(), [], ...$refused), Flows::settings(), $plan))
        ->toEqual(CannotJudge::because('Pest is not installed.'));
});
