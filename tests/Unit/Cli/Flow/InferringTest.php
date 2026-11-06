<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Inferring;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Subsumed;

afterEach(function (): void {
    Scratch::sweep();
});

/** The ledgers of a pull request whose default branch learned that the first mutant subsumes the second. */
function inferringLedgers(Mutant $by, Mutant $of): Ledgers
{
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withLearned(Subsumption::learned($by, $of)));
    $standing = Standing::of(
        new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main'))),
        RepositoryFake::onMain(Revision::ref('head')),
        Absent::setting(),
    );

    return $standing instanceof Standing
        ? Ledgers::read($store, $standing, Writing::Auto)
        : throw new RuntimeException($standing->why());
}

/** The results of `src/Money.php` holding these mutants. */
function inferringResults(Mutant ...$mutants): UnitResults
{
    return UnitResults::of(UnitResult::of(Unit::file(Path::of('src/Money.php')), Origin::Run, Mutants::of(...$mutants)));
}

/**
 * What the second mutant, left unrun, says beside the first, killed, in a run with this environment and config.
 *
 * @param array<string, string> $environment
 * @param array<string, mixed>  $config
 */
function inferredReason(Mutant $by, Mutant $of, array $environment, array $config): string
{
    $inferred = new Inferring(Flows::adapters(Scratch::directory(), $environment), Configs::settings(['runner' => 'pest', ...$config]))
        ->of(inferringResults($of->unjudged(OutOfTime::BeforeMutating)), inferringLedgers($by, $of), inferringResults($by));
    $why = [...[...$inferred][0]->mutants()][0]->reason();

    return $why instanceof Reason ? $why->text() : '';
}

it('infers the kills of mutants the budget left unrun outside CI under local.inferSubsumed, by what the default branch learned, and nowhere else', function (): void {
    $by = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $of = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');

    expect(inferredReason($by, $of, [], []))->toStartWith(sprintf('Inferred killed: %s ', $by->id()->value()))
        ->and(inferredReason($by, $of, [], ['local' => ['inferSubsumed' => false]]))->toBe(OutOfTime::BeforeMutating->reason()->text())
        ->and(inferredReason($by, $of, ['CI' => 'true'], []))->toBe(OutOfTime::BeforeMutating->reason()->text());
});

it('holds subsumed mutants back by what the ledgers learned where it infers, and holds none back elsewhere', function (): void {
    $by = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $of = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $ledgers = inferringLedgers($by, $of);
    $holding = static fn(bool $inCi, bool $infers): Subsumption|NotGiven => new Inferring(
        Flows::adapters(Scratch::directory(), $inCi ? ['CI' => 'true'] : []),
        Configs::settings(['runner' => 'pest', 'local' => ['inferSubsumed' => $infers]]),
    )->holding($ledgers);

    expect($holding(inCi: false, infers: true))->toEqual(Subsumption::learned($by, $of))
        ->and($holding(inCi: true, infers: true))->toEqual(NotGiven::value())
        ->and($holding(inCi: false, infers: false))->toEqual(NotGiven::value());
});
