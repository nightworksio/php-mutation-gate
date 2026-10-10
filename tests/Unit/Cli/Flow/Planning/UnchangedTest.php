<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$at = static fn(): Instant => Instant::at(new DateTimeImmutable('2026-10-09T08:00:00Z'));

$passed = static fn(): Passed => Passed::of(Revision::ref('passed'), 'mutation / verdict', 1)->passedAt($at());

/** A plan over a project, made since a ref or a full one, where only this file changed since the commit that passed on `main`. */
$plan = static function (string $project, string $changed, Mode $mode, object ...$ports) use ($passed): Plan|CannotJudge {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withRuns(ScopeRuns::none()->passing($passed())));
    $checkout = new ChangeSourceFake(
        Revision::ref('passed'),
        Changes::of(Change::modified(Path::of($changed), Lines::none())),
        [Revision::workingTree()->name() => Flows::FILES, 'passed' => Flows::FILES],
    );

    return Planned::from(new Planning(Flows::adapters($project, [], $store, $checkout, ...$ports), Flows::settings(), Flows::setup())
        ->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));
};

it('plans nothing, before any coverage run, where only files that do not matter to the gate changed since the last commit that passed', function () use (
    $plan,
    $passed,
    $at,
): void {
    $project = Flows::project();
    $runner = new CoverageAsked(RunnerFake::ofTheFixture(), CoverageMap::empty());
    $made = $plan($project, 'README.md', Mode::since(Mode::LAST_PASSED), $runner);

    expect($made instanceof Plan ? [...$made] : $made)->toBe([])
        ->and($made instanceof Plan ? $made->briefing()->unchanged() : $made)->toEqual(Unchanged::since($passed(), $at()))
        ->and($made instanceof Plan ? $made->commit() : $made)->toEqual(Revision::ref(Flows::HEAD))
        ->and($made instanceof Plan ? $made->keys()->units() : $made)->toEqual(Paths::none())
        ->and($runner->asked())->toBe([])
        ->and(is_file(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project)))->toBeFalse();
});

it('plans as ever where a changed file matters to the gate, or the change is not read since the last commit that passed', function (string $since, string $changed) use (
    $plan,
): void {
    $made = $plan(Flows::project(), $changed, $since === '' ? Mode::full() : Mode::since($since));

    expect($made instanceof Plan ? $made->briefing()->unchanged() : $made)->toEqual(NotGiven::value());
})->with([
    'a PHP file' => [Mode::LAST_PASSED, 'src/Money.php'],
    'the baseline' => [Mode::LAST_PASSED, 'mutation-gate.baseline.json'],
    'a change read since a ref' => ['passed', 'README.md'],
    'a full run' => ['', 'README.md'],
]);

it('plans as ever a run narrowed to some mutators or one suite, though nothing that matters changed', function (Narrowing $narrowing) use (
    $plan,
): void {
    $made = $plan(Flows::project(), 'README.md', Mode::since(Mode::LAST_PASSED), $narrowing);

    expect($made instanceof Plan ? $made->briefing()->unchanged() : $made)->toEqual(NotGiven::value());
})->with([
    'to the security mutators' => [fn(): Narrowing => Narrowing::none()->toMutators(Mutators::named('security/HashEqualsToTrue'))],
    'to one suite' => [fn(): Narrowing => Narrowing::none()->toSuite(SuiteName::of('unit'))],
]);
