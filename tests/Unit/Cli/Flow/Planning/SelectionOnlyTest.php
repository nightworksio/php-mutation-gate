<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A pull request's plan in a project of its own, of a mode, read through
 * this checkout where one is given, cut into two shards, or stopped at its
 * selection.
 */
$plan = static fn(
    Mode $mode,
    bool $selectionOnly,
    ChangeSourceFake|NotGiven $checkout = new NotGiven(),
): Plan|CannotJudge => Planned::from(new Planning(
    Flows::adapters(
        Flows::project(),
        [],
        new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main'))),
        ...$checkout instanceof ChangeSourceFake ? [$checkout] : [],
    ),
    Flows::settings(),
    Flows::setup(),
    selectionOnly: $selectionOnly,
)->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(2), MatrixKind::FirstKiller));

/**
 * The units a plan runs, by path, in every shard, sorted, and why it reached
 * them; why there is no plan, where there is none.
 *
 * @return array{list<string>, list<string>}
 */
$selection = static function (Plan|CannotJudge $plan): array {
    if ($plan instanceof CannotJudge) {
        return [[$plan->why()], []];
    }

    $units = array_merge(...array_map(
        static fn(Shard $shard): array => array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$shard->units()]),
        [...$plan],
    ));
    sort($units);

    return [$units, array_map(static fn(Reason $reason): string => $reason->text(), [...$plan->considered()->reach()])];
};

it('selects what a full plan selects, and why, in one uncut shard that names no tests', function (
    Mode $mode,
) use ($plan, $selection): void {
    $changed = Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))));
    $checkout = static fn(): ChangeSourceFake => new ChangeSourceFake(Revision::ref(Flows::MAIN), $changed, [
        Revision::workingTree()->name() => Flows::FILES,
        Flows::MAIN => Flows::FILES,
    ]);

    $full = $plan($mode, selectionOnly: false, checkout: $checkout());
    $alone = $plan($mode, selectionOnly: true, checkout: $checkout());

    [$units, $reasons] = $selection($full);

    expect($full)->toBeInstanceOf(Plan::class)
        ->and($alone)->toBeInstanceOf(Plan::class)
        ->and($units)->not->toBe([])
        ->and($reasons)->not->toBe([])
        ->and($selection($alone))->toBe([$units, $reasons])
        ->and($alone instanceof Plan ? $alone->considered() : $alone)->toEqual($full instanceof Plan ? $full->considered() : $full)
        ->and($alone instanceof Plan ? $alone->keys() : $alone)->toEqual($full instanceof Plan ? $full->keys() : $full)
        ->and($alone instanceof Plan ? count($alone) : $alone)->toBe(1)
        ->and($alone instanceof Plan ? $alone->names() : $alone)
        ->toEqual(CannotJudge::because('A plan that stops at its selection names no tests (ADR-0290).'));
})->with([
    'since the default branch' => [fn(): Mode => Mode::since(Flows::MAIN)],
    'in full' => [fn(): Mode => Mode::full()],
]);

it('cannot select where git cannot tell a pull request\'s new code, as a full plan cannot', function () use ($plan): void {
    expect($plan(Mode::full(), selectionOnly: true))->toEqual($plan(Mode::full(), selectionOnly: false))
        ->and($plan(Mode::full(), selectionOnly: true))->toBeInstanceOf(CannotJudge::class);
});
