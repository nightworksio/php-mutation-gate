<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Stub\Unstubbable;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** A mutant of `src/Cart.php`'s seventh line, judged so, its record giving this reason where one is given. */
$judged = static function (MutantJudgement $judgement, string $reason = ''): JudgedMutant {
    $mutant = Verdicts::mutant('src/Cart.php:7', 'LessThan', MutatorFamily::Boundary, Verdicts::diff('return $a < $b;', 'return $a <= $b;'));

    return JudgedMutant::of($reason === '' ? $mutant : $mutant->because(Reason::that($reason)), $judgement);
};

/** Why a mutant gets no stub, or that it gets one. */
$why = static function (JudgedMutant|JudgedKill $judged): string {
    $checked = Unstubbable::checked($judged);

    return $checked instanceof CannotJudge ? $checked->why() : 'stubbed';
};

it('stubs a survivor and an uncovered mutant', function (MutantJudgement $judgement) use ($judged, $why): void {
    expect($why($judged($judgement)))->toBe('stubbed');
})->with([MutantJudgement::Survived, MutantJudgement::Uncovered]);

it('has nothing to stub for a killed, ignored or proven equivalent mutant, nor for a proved kill', function (MutantJudgement $judgement) use ($judged, $why): void {
    $mutant = $judged($judgement);

    expect($why($mutant))->toBe(sprintf(
        'Mutant %s is %s: nothing to stub. %s',
        $mutant->mutant()->id()->value(),
        Label::of($judgement),
        $mutant->hint()->text(),
    ));
})->with([
    MutantJudgement::Killed,
    MutantJudgement::KilledByStaticAnalysis,
    MutantJudgement::Errored,
    MutantJudgement::KilledByTimeout,
    MutantJudgement::KilledByMemoryCap,
    MutantJudgement::Ignored,
    MutantJudgement::IgnoredByMarker,
    MutantJudgement::Equivalent,
]);

it('has nothing to stub for a kill a ledger proved', function () use ($why): void {
    $id = MutantId::hash(Path::of('src/Cart.php'), 'LessThan', '', 7);
    $kill = JudgedKill::of(ProvedKill::of($id, Path::of('src/Cart.php'), Line::of(7), 'LessThan', TestIds::none()));

    expect($why($kill))->toBe(sprintf('Mutant %s is killed: nothing to stub. A test fails with it in place.', $id->value()));
});

it('gives the next step instead for a flaky, too slow, too heavy or unjudged mutant', function (MutantJudgement $judgement, string $reason, string $said) use ($judged, $why): void {
    $mutant = $judged($judgement, $reason);

    expect($why($mutant))->toBe(sprintf($said, $mutant->mutant()->id()->value(), $mutant->hint()->text()));
})->with([
    'flaky' => [MutantJudgement::Flaky, '', 'Mutant %s is flaky, so a test written for it now may pass by chance. %s Run mutation-gate triage src/Cart.php to see.'],
    'too slow' => [MutantJudgement::TooSlowToJudge, '', 'Mutant %s is too slow to judge, so no test can be seen to kill it yet. %s'],
    'too heavy' => [MutantJudgement::TooHeavyToJudge, '', 'Mutant %s is too heavy to judge, so no test can be seen to kill it yet. %s'],
    'unjudged' => [MutantJudgement::Unjudged, 'the budget ran out', 'Mutant %s is unjudged. %s Run mutation-gate to judge it first.'],
    'unreached' => [MutantJudgement::Unjudged, Reason::UNREACHED, 'No test reaches the value mutant %s changes. Write a test that references it, and run mutation-gate to judge it.'],
]);
