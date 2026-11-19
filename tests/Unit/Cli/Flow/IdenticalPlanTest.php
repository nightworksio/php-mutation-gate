<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Reuse;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\StandsBecause;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\JudgingRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A run on pull request 7, into main, from a fork where it says so. */
function identicalOnPull(bool $fork = false): CiPlanFake
{
    $run = RunOn::at(Scope::pullRequest(7), Scope::branch('main'));

    return new CiPlanFake($fork ? $run->fromAFork() : $run);
}

/** The fixture project as git lists it, nothing changed since the default branch, which is fetched. */
function identicalCheckout(): ChangeSourceFake
{
    return new ChangeSourceFake(
        Revision::ref(Flows::MAIN),
        Changes::none(),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES, Flows::MAIN => Flows::FILES],
    );
}

/**
 * A plan over the fixture project, in this mode, with these settings and ports.
 *
 * @param list<Setting> $settings
 */
function identicalPlanned(string $project, Mode $mode, array $settings, object ...$ports): Plan
{
    $plan = Planned::from(new Planning(
        Flows::adapters($project, [], ...[...$ports, identicalCheckout()]),
        Flows::settings(...$settings),
        Flows::setup(),
    )->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));

    return $plan instanceof Plan ? $plan : throw new LogicException($plan->why());
}

/** A pass of this commit whose run read these inputs, under the fixture's check, judged whole where it says so. */
function identicalPass(string $commit, Digest $inputs, bool $whole = false, string $check = 'mutation / verdict'): Passed
{
    return Passed::of(Revision::ref($commit), $check, 0)
        ->passedAt(Moment::at('2026-11-19T10:00:00Z'))
        ->judgedIn(JudgingRun::of($inputs, sprintf('https://ci.example/runs/%s', $commit), $whole));
}

/** A store whose ledger of this scope records this pass. */
function identicalStore(Scope $scope, Passed $passed): ProofStoreFake
{
    $store = new ProofStoreFake();
    $store->write($scope, Ledger::empty()->withRuns(ScopeRuns::none()->passing($passed)));

    return $store;
}

/** Where a plan's standing verdict was read, or what it says where none stands. */
function identicalFrom(Plan $plan): string
{
    $standing = $plan->briefing()->unchanged();

    return $standing instanceof Unchanged && $standing->because() === StandsBecause::IdenticalInputs
        ? $standing->from()
        : 'judged';
}

/**
 * The digest of the inputs a plan on pull request 7 reads, with these variables set and these settings.
 *
 * @param array<string, string> $environment
 */
function identicalInputs(array $environment = [], Setting ...$settings): Digest
{
    $plan = Planned::from(new Planning(
        Flows::adapters(Flows::project(), $environment, identicalOnPull(), identicalCheckout()),
        Flows::settings(...$settings),
        Flows::setup(),
    )->plan(Mode::since(Mode::LAST_PASSED), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));
    $inputs = $plan instanceof Plan ? $plan->briefing()->inputs()->digest() : throw new LogicException($plan->why());

    return $inputs instanceof Digest ? $inputs : throw new LogicException('The plan read no inputs.');
}

$inputs = static fn(): Digest => identicalInputs();

it('reads the inputs of every run, and reads the same of the same project', function () use ($inputs): void {
    expect($inputs())->toEqual($inputs())
        ->and($inputs())->not->toEqual(Digest::ofNothing());
});

it('reuses on a pull request its own earlier pass, or the default branch\'s, whose run read identical inputs', function (Scope $scope, string $from) use ($inputs): void {
    $plan = identicalPlanned(Flows::project(), Mode::since(Mode::LAST_PASSED), [], identicalOnPull(), identicalStore($scope, identicalPass(str_repeat('a', 40), $inputs())));
    $standing = $plan->briefing()->unchanged();

    expect(identicalFrom($plan))->toBe($from)
        ->and([...$plan])->toHaveCount(0)
        ->and($plan->briefing()->inputs()->digest())->toEqual($inputs())
        ->and($standing instanceof Unchanged ? $standing->reason()->text() : $standing)->toContain(sprintf('https://ci.example/runs/%s', str_repeat('a', 40)));
})->with([
    'its own' => [fn(): Scope => Scope::pullRequest(7), 'refs/pull/7'],
    'the default branch\'s' => [fn(): Scope => Scope::branch('main'), 'refs/heads/main'],
]);

it('judges where the pass read other inputs, reported under another check, or does not say when it passed', function (Passed $passed): void {
    expect(identicalFrom(identicalPlanned(Flows::project(), Mode::since(Mode::LAST_PASSED), [], identicalOnPull(), identicalStore(Scope::pullRequest(7), $passed))))
        ->toBe('judged');
})->with([
    'other inputs' => [fn(): Passed => identicalPass(str_repeat('a', 40), Digest::sha256Of('other inputs'))],
    'another check' => [fn(): Passed => identicalPass(str_repeat('a', 40), Digest::sha256Of('x'), check: 'other')],
    'no inputs' => [fn(): Passed => Passed::of(Revision::ref(str_repeat('a', 40)), 'mutation / verdict', 0)->passedAt(Moment::at('2026-11-19T10:00:00Z'))],
]);

it('reads no pass of its own scope on a pull request from a fork, unless reuse.forks says, and the default branch\'s all the same', function (Scope $scope, Setting $forks, string $from) use ($inputs): void {
    $plan = identicalPlanned(Flows::project(), Mode::since(Mode::LAST_PASSED), [$forks], identicalOnPull(fork: true), identicalStore($scope, identicalPass(str_repeat('a', 40), $inputs())));

    expect(identicalFrom($plan))->toBe($from);
})->with([
    'its own, forks off' => [fn(): Scope => Scope::pullRequest(7), fn(): Setting => Reuse::notFromForks(), 'judged'],
    'its own, forks on' => [fn(): Scope => Scope::pullRequest(7), fn(): Setting => Reuse::fromForks(), 'refs/pull/7'],
    'the default branch\'s' => [fn(): Scope => Scope::branch('main'), fn(): Setting => Reuse::notFromForks(), 'refs/heads/main'],
]);

it('reuses on a pull request under on and pulls, and nothing under off', function (Setting $identical, string $from) use ($inputs): void {
    expect(identicalFrom(identicalPlanned(Flows::project(), Mode::since(Mode::LAST_PASSED), [$identical], identicalOnPull(), identicalStore(Scope::pullRequest(7), identicalPass(str_repeat('a', 40), $inputs())))))
        ->toBe($from);
})->with([
    'on' => [fn(): Setting => Reuse::identical(), 'refs/pull/7'],
    'pulls' => [fn(): Setting => Reuse::identicalOnPullRequests(), 'refs/pull/7'],
    'off' => [fn(): Setting => Reuse::neverIdentical(), 'judged'],
]);

it('reuses on the default branch its own earlier pass under on alone', function (Setting $identical, string $from): void {
    $main = new CiPlanFake(RunOn::at(Scope::branch('main'), Scope::branch('main')));
    $inputs = identicalPlanned(Flows::project(), Mode::since(Mode::LAST_PASSED), [$identical], $main)->briefing()->inputs()->digest();
    $store = identicalStore(Scope::branch('main'), identicalPass(str_repeat('a', 40), $inputs instanceof Digest ? $inputs : Digest::ofNothing()));

    expect(identicalFrom(identicalPlanned(Flows::project(), Mode::since(Mode::LAST_PASSED), [$identical], $main, $store)))->toBe($from);
})->with([
    'on' => [fn(): Setting => Reuse::identical(), 'refs/heads/main'],
    'pulls' => [fn(): Setting => Reuse::identicalOnPullRequests(), 'judged'],
    'off' => [fn(): Setting => Reuse::neverIdentical(), 'judged'],
]);

it('reuses for a run of every unit only a pass whose run carried none, and none for a run sampled or taking no proof', function (Mode $mode, bool $whole, string $from) use ($inputs): void {
    expect(identicalFrom(identicalPlanned(Flows::project(), $mode, [], identicalOnPull(), identicalStore(Scope::pullRequest(7), identicalPass(str_repeat('a', 40), $inputs(), $whole)))))
        ->toBe($from);
})->with([
    'full, of a pass that carried some' => [fn(): Mode => Mode::full(), false, 'judged'],
    'full, of a pass that carried none' => [fn(): Mode => Mode::full(), true, 'refs/pull/7'],
    'sampled' => [fn(): Mode => Mode::since(Mode::LAST_PASSED)->sampled(), true, 'judged'],
    'taking no proof' => [fn(): Mode => Mode::unproven(), true, 'judged'],
]);

it('reads among a run\'s inputs the value of each variable reuse.environment names, and of no other', function () use ($inputs): void {
    $read = identicalInputs(...);

    expect($read(['APP_ENV' => 'production']))->toEqual($inputs())
        ->and($read(['APP_ENV' => 'production'], Reuse::environment('APP_ENV')))->not->toEqual($inputs())
        ->and($read(['APP_ENV' => 'production'], Reuse::environment('APP_ENV')))->not->toEqual($read(['APP_ENV' => 'staging'], Reuse::environment('APP_ENV')))
        ->and($read([], Reuse::environment('APP_ENV')))->toEqual($inputs())
        ->and($read(['ImageOS' => 'ubuntu26']))->not->toEqual($inputs());
});
