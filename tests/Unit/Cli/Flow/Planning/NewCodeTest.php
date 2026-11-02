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
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A plan over the project, with these ports in place of the fakes. */
$plan = (static fn(string $project, Mode $mode, Cut $cut, object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
    Flows::adapters($project, [], ...$ports),
    Flows::settings(),
    Flows::setup(),
)->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), $cut, MatrixKind::FirstKiller)));

it('hands a full pull request plan the lines changed since the default branch, for new code', function () use (
    $plan,
): void {
    $pullRequest = new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $changed = Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))));
    $checkout = new ChangeSourceFake(Revision::ref(Flows::MAIN), $changed, [
        Revision::workingTree()->name() => Flows::FILES,
        Flows::MAIN => Flows::FILES,
    ]);

    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $pullRequest, $checkout);

    expect($planned instanceof Plan ? $planned->considered()->changed() : $planned)->toEqual($changed);
});

it('hands a full run of a branch no lines for new code, whatever changed since the default branch', function () use (
    $plan,
): void {
    $changed = Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))));
    $checkout = new ChangeSourceFake(Revision::ref(Flows::MAIN), $changed, [
        Revision::workingTree()->name() => Flows::FILES,
        Flows::MAIN => Flows::FILES,
    ]);

    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $checkout);

    expect($planned instanceof Plan ? $planned->considered()->changed() : $planned)->toEqual(Changes::none());
});

it('cannot plan a full pull request run where git cannot tell what changed since the default branch', function () use (
    $plan,
): void {
    $pullRequest = new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));

    expect($plan(Flows::project(), Mode::full(), Cut::exactly(1), $pullRequest))->toEqual(CannotJudge::because(sprintf(
        "%s %s\n%s\n%s",
        'A pull request\'s or merge group\'s new code is judged against refs/remotes/origin/main,',
        'and git cannot tell what changed since it,',
        'so no floor for new code can be held. refs/remotes/origin/main is not a revision this repository has.',
        'Fetch it into the checkout before the plan.',
    )));
});

it('judges a full merge group run\'s new code since the commit its queue built it on', function () use (
    $plan,
): void {
    $group = new CiPlanFake(RunOn::mergeGroup(Revision::ref('85c3298'), Scope::branch('main')));

    expect($plan(Flows::project(), Mode::full(), Cut::exactly(1), $group))->toEqual(CannotJudge::because(sprintf(
        "%s %s\n%s\n%s",
        'A pull request\'s or merge group\'s new code is judged against 85c3298,',
        'and git cannot tell what changed since it,',
        'so no floor for new code can be held. 85c3298 is not a revision this repository has.',
        'Fetch it into the checkout before the plan.',
    )));
});
