<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\ChangeBase;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The ledgers of a pull request whose own ledger records this commit as passed, or none. */
function modeLedgers(string $passed): Ledgers
{
    $store = new ProofStoreFake();
    $ledger = $passed === ''
        ? Ledger::empty()
        : Ledger::empty()->withRuns(ScopeRuns::none()->passing(Passed::of(Revision::ref($passed), 'check', 0)));
    $store->write(Scope::pullRequest(7), $ledger);
    $ci = new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $standing = Standing::of($ci, RepositoryFake::onMain(Revision::ref('head')), Absent::setting());

    return $standing instanceof Standing
        ? Ledgers::read($store, $standing, Writing::Auto)
        : throw new RuntimeException($standing->why());
}

it('considers every unit in a full run', function (): void {
    expect(Mode::full()->base(modeLedgers('p1')))->toEqual(CannotTell::because('A full run considers every unit.'));
});

it('reads the change since the ref it names', function (): void {
    expect(Mode::since('v1.2')->base(modeLedgers('p1')))->toEqual(Revision::ref('v1.2'));
});

it('reads the change since the newest commit of its own scope that passed', function (): void {
    expect(Mode::since(Mode::LAST_PASSED)->base(modeLedgers('p1')))->toEqual(Revision::ref('p1'));
});

it('is full where no commit of its scope has passed yet', function (): void {
    expect(Mode::since('last-passed')->base(modeLedgers('')))->toEqual(CannotTell::because(
        'No commit of this scope has passed yet, so the run considers every unit.',
    ));
});

/**
 * A run's ledgers and where it stands, on a scope whose own ledger holds these runs, beside the default branch's,
 * which holds a passed commit.
 *
 * @return array{Ledgers, Standing}
 */
function modeStanding(Scope $scope, ScopeRuns $runs): array
{
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withRuns(ScopeRuns::none()->passing(Passed::of(Revision::ref('main-passed'), 'check', 0))));
    $store->write($scope, Ledger::empty()->withRuns($runs));
    $ci = new CiPlanFake(RunOn::at($scope, Scope::branch('main')));
    $standing = Standing::of($ci, RepositoryFake::onMain(Revision::ref('head')), Absent::setting());

    return $standing instanceof Standing
        ? [Ledgers::read($store, $standing, Writing::Auto), $standing]
        : throw new RuntimeException($standing->why());
}

$lastRun = static fn(string $check = 'check', ?RunProfile $kind = null): ScopeRuns
    => ScopeRuns::none()->lastRunAt(LastRun::of(JudgedCommits::of('last-run'), $check, $kind ?? RunProfile::standard()));

it('reads a pull request\'s change from its own last run of the same kind and check, falling back to the default branch it fetched', function () use ($lastRun): void {
    [$ledgers, $standing] = modeStanding(Scope::pullRequest(7), $lastRun());
    $base = Mode::since(Mode::LAST_RUN)->changeBase($ledgers, $standing, RunProfile::standard(), 'check');

    expect($base instanceof ChangeBase ? [$base->lastRunCommit(), $base->ref()] : $base)
        ->toEqual([JudgedCommits::of('last-run'), $standing->fetchedDefaultBranch()]);
});

it('reads a change since the ref alone where its scope\'s last run cannot stand for this one', function (Scope $scope, ScopeRuns $runs, RunProfile $kind, string $since): void {
    [$ledgers, $standing] = modeStanding($scope, $runs);
    $base = Mode::since(Mode::LAST_RUN)->changeBase($ledgers, $standing, $kind, 'check');
    expect($base instanceof ChangeBase ? [$base->lastRunCommit(), $base->ref()->name()] : $base)
        ->toEqual([NotGiven::value(), $since === '' ? $standing->fetchedDefaultBranch()->name() : $since]);
})->with([
    'no run on record' => [fn(): Scope => Scope::pullRequest(7), ScopeRuns::none(), RunProfile::standard(), ''],
    'a run cut short since' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun()->cutShort(), RunProfile::standard(), ''],
    'a run of another kind' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun(), fn(): RunProfile => RunProfile::standard()->securityOnly(), ''],
    'a run that recorded every killer' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun(), fn(): RunProfile => RunProfile::standard()->recording(MatrixKind::Full), ''],
    'a run of one suite' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun(), fn(): RunProfile => RunProfile::standard()->inSuite(SuiteName::of('unit')), ''],
    'a run under another check' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun('lint'), RunProfile::standard(), ''],
    'the default branch, since its last commit that passed' => [fn(): Scope => Scope::branch('main'), fn(): ScopeRuns => $lastRun()->passing(Passed::of(Revision::ref('main-passed'), 'check', 0)), RunProfile::standard(), 'main-passed'],
]);

it('cannot name the commit a run judged for a command that only lists what a change reaches', function (): void {
    expect(Mode::since(Mode::LAST_RUN)->base(modeLedgers('p1')))
        ->toEqual(CannotTell::because('`last-run` names the commit a run of the gate judged, so only `run` reads it.'));
});
