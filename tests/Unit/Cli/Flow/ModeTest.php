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
use NightWorksIO\MutationGate\Core\Config\DefaultBranchSince;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Judged;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Proof\TakenProofs;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\Moment;
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
        ? Ledgers::read($store, $standing, Writing::Auto, TakenProofs::Every)
        : throw new RuntimeException($standing->why());
}

it('considers every unit in a full run', function (): void {
    expect(Mode::full()->base(modeLedgers('p1')))->toEqual(CannotTell::because('A full run considers every unit.'));
});

it('takes the proofs the config has it take but in an unproven run, which considers every unit and takes none, sampled or not', function (): void {
    expect([
        Mode::full()->takes(TakenProofs::Every),
        Mode::since('main')->takes(TakenProofs::DefaultBranchOnly),
        Mode::unproven()->takes(TakenProofs::Every),
        Mode::unproven()->sampled()->takes(TakenProofs::DefaultBranchOnly),
    ])
        ->toBe([TakenProofs::Every, TakenProofs::DefaultBranchOnly, TakenProofs::None, TakenProofs::None])
        ->and(Mode::unproven()->isFull())->toBeTrue();
});

it('reads the change since the ref it names', function (): void {
    expect(Mode::since('v1.2')->base(modeLedgers('p1')))->toEqual(Revision::ref('v1.2'));
});

it('reads the change since the newest commit of its own scope that passed', function (): void {
    expect(Mode::since(Mode::LAST_PASSED)->base(modeLedgers('p1')))->toEqual(Revision::ref('p1'));
});

it('says whether it reads the change since the newest commit of its own scope that passed', function (): void {
    expect(Mode::since(Mode::LAST_PASSED)->isSinceLastPassed())->toBeTrue()
        ->and(Mode::since(Mode::LAST_RUN)->isSinceLastPassed())->toBeFalse()
        ->and(Mode::since('v1.2')->isSinceLastPassed())->toBeFalse()
        ->and(Mode::full()->isSinceLastPassed())->toBeFalse();
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
        ? [Ledgers::read($store, $standing, Writing::Auto, TakenProofs::Every), $standing]
        : throw new RuntimeException($standing->why());
}

$lastRun = static fn(string $check = 'check'): ScopeRuns
    => ScopeRuns::none()->lastRunAt(LastRun::of(JudgedCommits::of('last-run'), $check, RunProfile::standard()));

it('reads a pull request\'s change from its own last run of the same kind and check, falling back to the default branch it fetched', function () use ($lastRun): void {
    [$ledgers, $standing] = modeStanding(Scope::pullRequest(7), $lastRun());
    $base = Mode::since(Mode::LAST_RUN)->changeBase($ledgers, $standing, RunProfile::standard(), 'check', DefaultBranchSince::Passed);

    expect($base instanceof ChangeBase ? [$base->lastRunCommit(), $base->ref()] : $base)
        ->toEqual([JudgedCommits::of('last-run'), $standing->fetchedDefaultBranch()]);
});

it('reads a change since the ref alone where its scope\'s last run cannot stand for this one', function (Scope $scope, ScopeRuns $runs, RunProfile $kind, string $since): void {
    [$ledgers, $standing] = modeStanding($scope, $runs);
    $base = Mode::since(Mode::LAST_RUN)->changeBase($ledgers, $standing, $kind, 'check', DefaultBranchSince::Passed);
    expect($base instanceof ChangeBase ? [$base->lastRunCommit(), $base->ref()->name()] : $base)
        ->toEqual([NotGiven::value(), $since === '' ? $standing->fetchedDefaultBranch()->name() : $since]);
})->with([
    'no run on record' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => ScopeRuns::none(), fn(): RunProfile => RunProfile::standard(), ''],
    'a run cut short since' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun()->cutShort(), fn(): RunProfile => RunProfile::standard(), ''],
    'a run of another kind' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun(), fn(): RunProfile => RunProfile::standard()->securityOnly(), ''],
    'a run that recorded every killer' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun(), fn(): RunProfile => RunProfile::standard()->recording(MatrixKind::Full), ''],
    'a run of one suite' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun(), fn(): RunProfile => RunProfile::standard()->inSuite(SuiteName::of('unit')), ''],
    'a run under another check' => [fn(): Scope => Scope::pullRequest(7), fn(): ScopeRuns => $lastRun('lint'), fn(): RunProfile => RunProfile::standard(), ''],
    'the default branch, since its last commit that passed' => [fn(): Scope => Scope::branch('main'), fn(): ScopeRuns => $lastRun()->passing(Passed::of(Revision::ref('main-passed'), 'check', 0)), fn(): RunProfile => RunProfile::standard(), 'main-passed'],
]);

it('cannot name the commit a run judged for a command that only lists what a change reaches', function (): void {
    expect(Mode::since(Mode::LAST_RUN)->base(modeLedgers('p1')))
        ->toEqual(CannotTell::because('`last-run` names the commit a run of the gate judged, so only `run` reads it.'));
});

it('samples neither a full run nor a change-scoped one until asked, keeping what it considers', function (): void {
    expect(Mode::full()->isSampled())->toBeFalse()
        ->and(Mode::since('origin/main')->isSampled())->toBeFalse()
        ->and(Mode::full()->sampled()->isSampled())->toBeTrue()
        ->and(Mode::full()->sampled()->isFull())->toBeTrue()
        ->and(Mode::since(Mode::LAST_PASSED)->sampled()->isSinceLastPassed())->toBeTrue();
});

it('samples the reached units too only when it samples of its own accord, keeping what it considers', function (): void {
    $itself = Mode::since(Mode::LAST_PASSED)->sampledByItself();

    expect($itself->isSampled())->toBeTrue()
        ->and($itself->samplesReached())->toBeTrue()
        ->and($itself->isSinceLastPassed())->toBeTrue()
        ->and(Mode::full()->sampled()->samplesReached())->toBeFalse()
        ->and(Mode::full()->samplesReached())->toBeFalse()
        ->and(Mode::unproven()->sampledByItself()->takes(TakenProofs::Every))->toBe(TakenProofs::None);
});

it('reads, off the default branch, its change also since the commit the default branch\'s ledger last passed at, and on it not', function () use ($lastRun): void {
    [$pullRequest, $standing] = modeStanding(Scope::pullRequest(7), $lastRun());
    [$onMain, $mainStanding] = modeStanding(Scope::branch('main'), ScopeRuns::none()->passing(Passed::of(Revision::ref('main-passed'), 'check', 0)));
    $since = Mode::since('base')->changeBase($pullRequest, $standing, RunProfile::standard(), 'check', DefaultBranchSince::Passed);
    $lastRunBase = Mode::since(Mode::LAST_RUN)->changeBase($pullRequest, $standing, RunProfile::standard(), 'check', DefaultBranchSince::Passed);
    $main = Mode::since(Mode::LAST_PASSED)->changeBase($onMain, $mainStanding, RunProfile::standard(), 'check', DefaultBranchSince::Passed);

    expect($since instanceof ChangeBase ? $since->ledgerAt() : $since)->toEqual(Revision::ref('main-passed'))
        ->and($lastRunBase instanceof ChangeBase ? $lastRunBase->ledgerAt() : $lastRunBase)->toEqual(Revision::ref('main-passed'))
        ->and($main instanceof ChangeBase ? [$main->ref(), $main->ledgerAt()] : $main)->toEqual([Revision::ref('main-passed'), NotGiven::value()]);
});

/**
 * The ledgers and the standing of a pull request's run, where no ledger records anything.
 *
 * @return array{Ledgers, Standing}
 */
function modeStandingUnrun(): array
{
    $standing = Standing::of(new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main'))), RepositoryFake::onMain(Revision::ref('head')), Absent::setting());

    return $standing instanceof Standing
        ? [Ledgers::read(new ProofStoreFake(), $standing, Writing::Auto, TakenProofs::Every), $standing]
        : throw new RuntimeException($standing->why());
}

it('reads no change since the default branch\'s ledger where that ledger records no commit that passed', function (): void {
    [$ledgers, $standing] = modeStandingUnrun();
    $base = Mode::since('base')->changeBase($ledgers, $standing, RunProfile::standard(), 'check', DefaultBranchSince::Passed);

    expect($base instanceof ChangeBase ? $base->ledgerAt() : $base)->toEqual(NotGiven::value());
});

/**
 * A run's ledgers and where it stands, on this scope, the default branch's ledger holding a pass and a judged run
 * of the same check after it that left src/Open.php open.
 *
 * @return array{Ledgers, Standing}
 */
function modeStandingJudged(Scope $scope): array
{
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withRuns(ScopeRuns::none()
        ->passing(Passed::of(Revision::ref('main-passed'), 'check', 0)->passedAt(Moment::at('2026-11-09T10:00:00Z')))
        ->judging(Judged::of(Revision::ref('main-judged'), 'check', Moment::at('2026-11-09T12:00:00Z'), Paths::of(Path::of('src/Open.php'))))));
    $standing = Standing::of(new CiPlanFake(RunOn::at($scope, Scope::branch('main'))), RepositoryFake::onMain(Revision::ref('head')), Absent::setting());

    return $standing instanceof Standing
        ? [Ledgers::read($store, $standing, Writing::Auto, TakenProofs::Every), $standing]
        : throw new RuntimeException($standing->why());
}

it('reads the default branch\'s change since its newest judged run, the units it left open since its last pass, where it reads since judged runs', function (): void {
    [$ledgers, $standing] = modeStandingJudged(Scope::branch('main'));
    $judged = Mode::since(Mode::LAST_PASSED)->changeBase($ledgers, $standing, RunProfile::standard(), 'check', DefaultBranchSince::Judged);
    $passed = Mode::since(Mode::LAST_PASSED)->changeBase($ledgers, $standing, RunProfile::standard(), 'check', DefaultBranchSince::Passed);

    expect($judged instanceof ChangeBase ? [$judged->ref(), $judged->openSince(), $judged->open()] : $judged)
        ->toEqual([Revision::ref('main-judged'), Revision::ref('main-passed'), Paths::of(Path::of('src/Open.php'))])
        ->and($passed instanceof ChangeBase ? [$passed->ref(), $passed->openSince(), $passed->open()] : $passed)
        ->toEqual([Revision::ref('main-passed'), NotGiven::value(), Paths::none()]);
});

it('reads, off the default branch, its change also since the default branch\'s newest judged run, the units it left open since its last pass', function (): void {
    [$ledgers, $standing] = modeStandingJudged(Scope::pullRequest(7));
    $base = Mode::since('base')->changeBase($ledgers, $standing, RunProfile::standard(), 'check', DefaultBranchSince::JudgedOffBranch);

    expect($base instanceof ChangeBase ? [$base->ref(), $base->ledgerAt(), $base->openSince(), $base->open()] : $base)
        ->toEqual([Revision::ref('base'), Revision::ref('main-judged'), Revision::ref('main-passed'), Paths::of(Path::of('src/Open.php'))]);
});

it('takes no proof once a run is planned without a fresh map, keeping what it reads its change since', function (): void {
    $none = Mode::since('base')->takingNone();

    expect(Mode::since('base')->isUnproven())->toBeFalse()
        ->and($none->isUnproven())->toBeTrue()
        ->and($none->takes(TakenProofs::Every))->toBe(TakenProofs::None)
        ->and($none->isFull())->toBeFalse()
        ->and(Mode::since(Mode::LAST_PASSED)->takingNone()->isSinceLastPassed())->toBeTrue();
});
