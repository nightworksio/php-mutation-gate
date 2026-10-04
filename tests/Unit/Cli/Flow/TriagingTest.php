<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Triaging;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Triage\Repeated;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Varying;

afterEach(function (): void {
    Scratch::sweep();
});

/** A run of the unit that gave its one mutant this status. */
$gave = static fn(MutantStatus $status): MutationResult => MutationResult::of(Mutants::of(Varying::mutant(7, $status)), 0);

/** The kill history of the default branch's ledger: a function of `src/Money.php` and one of `src/Held.php`. */
$history = static function (): KillHistory {
    $ranked = Ranking::of(Kills::of(TestId::of('MoneyTest::adds'), 2));

    return KillHistory::none()
        ->withFunction(Enclosing::named(Path::of('src/Money.php'), 'add'), $ranked)
        ->withFunction(Enclosing::named(Path::of('src/Held.php'), 'double'), $ranked);
};

/** A store whose default branch's ledger holds that kill history. */
$store = static function () use ($history): ProofStoreFake {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withKillers($history()));

    return $store;
};

/**
 * The unit at a path triaged over these runs with its tests in this order,
 * and each run's number and mutants as they were handed on.
 *
 * @return array{Repeated|CannotJudge, list<array{int, int}>}
 */
$triage = static function (ScriptedRunner $runner, string $path, int $runs, TestOrder $order, object ...$ports): array {
    $handed = [];
    $triaged = new Triaging(Flows::adapters(Flows::project(), [], $runner, ...$ports), Flows::settings())->triaged(
        Path::of($path),
        max(2, $runs),
        $order,
        static function (int $run, MutationResult $result) use (&$handed): void {
            $handed[] = [$run, count($result->mutants())];
        },
    );

    return [$triaged, $handed];
};

it('runs a unit as many times as asked, handing each run on as it ends, and gives what every run made', function () use (
    $gave,
    $triage,
): void {
    $runner = ScriptedRunner::fixture()->behaving(RunnerBehaviour::standard()->runningPerCore())->answeringInTurn(
        $gave(MutantStatus::Killed),
        $gave(MutantStatus::Survived),
        $gave(MutantStatus::Killed),
    );

    [$triaged, $handed] = $triage($runner, 'src/Money.php', 3, TestOrder::Runner);

    expect($triaged instanceof Repeated ? [$triaged->runs(), count($triaged->varied())] : $triaged)->toBe([3, 1])
        ->and($handed)->toBe([[1, 1], [2, 1], [3, 1]])
        ->and(array_map(static fn(MutationRequest $request): array => [
            $request->files(),
            $request->judgedBy(),
            $request->processes(),
            $request->search()->matrix(),
        ], $runner->requests()))->toEqual(array_fill(0, 3, [
            Paths::of(Path::of('src/Money.php')),
            WholeSuite::tests(),
            Processes::of(2),
            MatrixKind::FirstKiller,
        ]));
});

it('runs a held path by the group that holds it', function () use ($triage): void {
    $runner = ScriptedRunner::fixture();

    $triage($runner, 'src/Held.php', 2, TestOrder::Runner);

    expect(array_map(static fn(MutationRequest $request): array => [$request->files(), $request->judgedBy()], $runner->requests()))
        ->toEqual(array_fill(0, 2, [Paths::of(Path::of('src/Held.php')), Group::named('holds:src/Held.php')]));
});

it('runs each mutant\'s likely killers first by what every ledger learned of the unit\'s files alone', function () use (
    $triage,
    $history,
    $store,
): void {
    $runner = ScriptedRunner::fixture();

    $triage($runner, 'src/Money.php', 2, TestOrder::KillersFirst, $store());
    $kept = $history()->onlyIn(Paths::of(Path::of('src/Money.php')));

    expect(array_map(static fn(MutationRequest $request): Ordering => $request->search()->ordering(), $runner->requests()))
        ->toEqual(array_fill(0, 2, Ordering::of(TestOrder::KillersFirst, $kept)))
        ->and([...$kept->functions()])->toHaveCount(1);
});

it('learns of every file within a held path, and of none beside it', function () use ($triage, $history, $store): void {
    $runner = ScriptedRunner::fixture()->listing(Groups::of(Group::named('holds:src')));

    $triage($runner, 'src', 2, TestOrder::KillersFirst, $store());

    expect(array_map(static fn(MutationRequest $request): Ordering => $request->search()->ordering(), $runner->requests()))
        ->toEqual(array_fill(0, 2, Ordering::of(TestOrder::KillersFirst, $history())));
});

it('learns of a held file itself', function () use ($triage, $history, $store): void {
    $runner = ScriptedRunner::fixture();

    $triage($runner, 'src/Held.php', 2, TestOrder::KillersFirst, $store());
    $kept = $history()->onlyIn(Paths::of(Path::of('src/Held.php')));

    expect(array_map(static fn(MutationRequest $request): Ordering => $request->search()->ordering(), $runner->requests()))
        ->toEqual(array_fill(0, 2, Ordering::of(TestOrder::KillersFirst, $kept)))
        ->and([...$kept->functions()])->toHaveCount(1);
});

it('runs each mutant\'s tests in its runner\'s order where asked', function () use ($triage, $store): void {
    $runner = ScriptedRunner::fixture();

    $triage($runner, 'src/Money.php', 2, TestOrder::Runner, $store());

    expect(array_map(
        static fn(MutationRequest $request): bool => $request->search()->ordering()->putsKillersFirst(),
        $runner->requests(),
    ))->toBe([false, false]);
});

it('refuses a path that is not a unit the gate mutates', function () use ($triage): void {
    $runner = ScriptedRunner::fixture();

    [$triaged] = $triage($runner, 'src/Missing.php', 2, TestOrder::Runner);

    expect($triaged)->toEqual(CannotJudge::because(
        'src/Missing.php is not a unit the gate mutates: give a file of a tree, or a held path.',
    ))->and($runner->requests())->toBe([]);
});

it('cannot triage where the units cannot be found', function () use ($triage): void {
    [$triaged] = $triage(ScriptedRunner::fixture()->unlisted('the runner cannot list its groups'), 'src/Money.php', 2, TestOrder::Runner);

    expect($triaged)->toBeInstanceOf(CannotJudge::class);
});

it('stops at the first run that cannot judge, and says which run of how many it was', function () use ($gave, $triage): void {
    $runner = ScriptedRunner::fixture()->answeringInTurn($gave(MutantStatus::Killed), CannotJudge::because('the runner crashed.'));

    [$triaged, $handed] = $triage($runner, 'src/Money.php', 4, TestOrder::Runner);

    expect($triaged)->toEqual(CannotJudge::because('Run 2 of 4 cannot judge: the runner crashed.'))
        ->and($handed)->toBe([[1, 1]])
        ->and($runner->requests())->toHaveCount(2);
});
