<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\HeldTo;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Judged;

$tree = static fn(string $path, JudgedUnits $units, JudgedMutants $mutants): TreeVerdict => TreeVerdict::judged(
    Tree::at(Path::of($path), Floor::of(100), Package::at(Path::root())),
    Unrecorded::floor(),
    $units,
    $mutants,
    Uncovered::Count,
);
$passed = $tree('src', JudgedUnits::none(), Judged::mutants(MutantJudgement::Killed));
$failed = $tree('src', JudgedUnits::none(), Judged::mutants(MutantJudgement::Survived));
$newCode = static fn(MutantJudgement $judgement): NewCodeVerdict => NewCodeVerdict::judged(
    Package::at(Path::root()),
    Floor::of(100),
    Judged::mutants($judgement),
    Uncovered::Count,
);

it('holds its trees, and nothing else to begin with', function () use ($passed): void {
    $trees = TreeVerdicts::of($passed);
    $verdict = Verdict::of($trees);

    expect($verdict->trees())->toBe($trees)
        ->and($verdict->newCode())->toHaveCount(0)
        ->and($verdict->reach())->toHaveCount(0)
        ->and($verdict->warnings())->toHaveCount(0)
        ->and($verdict->failures())->toHaveCount(0)
        ->and($verdict->obstacles())->toHaveCount(0)
        ->and($verdict->wasCutShort())->toBeFalse();
});

it('cannot judge when anything kept the run from judging, whatever its trees and failures say', function () use ($passed, $failed): void {
    $missing = CannotJudge::because('Shard 2 wrote no result.');
    $late = CannotJudge::because('Shard 3 ran on another commit.');
    $verdict = Verdict::of(TreeVerdicts::of($passed, $failed))->withCannotJudge($missing)->withCannotJudge($late);

    expect($verdict->judgement())->toBe(Judgement::CannotJudge)
        ->and([...$verdict->obstacles()])->toBe([$missing, $late])
        ->and($verdict->cutShort()->obstacles())->toHaveCount(2)
        ->and(Verdict::of(TreeVerdicts::of($passed))->withCannotJudge($missing)->judgement())->toBe(Judgement::CannotJudge);
});

it('says when a budget or a deadline cut the run short, and keeps everything else', function () use ($passed): void {
    $trees = TreeVerdicts::of($passed);
    $verdict = Verdict::of($trees)->cutShort();

    expect($verdict->wasCutShort())->toBeTrue()
        ->and($verdict->trees())->toBe($trees)
        ->and($verdict->withFailures(Failures::none())->wasCutShort())->toBeTrue();
});

it('takes the new-code sets, the reach, the warnings and the failures, each without losing the others', function () use ($passed, $newCode): void {
    $sets = NewCodeVerdicts::of($newCode(MutantJudgement::Killed));
    $reach = Reasons::of(Reason::that('src/Money.php changed.'));
    $warnings = Warnings::of(Warning::that('src/Kernel.php is run by 412 of 430 tests and nothing holds it.'));
    $failures = Failures::of(Failure::that('The ignore of src/Money.php:12 matched no mutant.'));
    $verdict = Verdict::of(TreeVerdicts::of($passed))
        ->withNewCode($sets)
        ->withReach($reach)
        ->withWarnings($warnings)
        ->withFailures($failures);
    $again = $verdict->withNewCode($sets)->withReach($reach)->withWarnings($warnings)->withFailures($failures);

    expect($again->newCode())->toBe($sets)
        ->and($again->reach())->toBe($reach)
        ->and($again->warnings())->toBe($warnings)
        ->and($again->failures())->toBe($failures)
        ->and($again->trees())->toEqual(TreeVerdicts::of($passed));
});

it('shows the trees it does not hold, and fails on its new code and the failures no floor decides', function (
    NewCodeVerdicts $newCode,
    Failures $failures,
    Judgement $whole,
) use ($failed): void {
    $verdict = Verdict::of(TreeVerdicts::of($failed), HeldTo::NewCode)->withNewCode($newCode)->withFailures($failures);

    expect($verdict->judgement())->toBe($whole)
        ->and($verdict->cutShort()->judgement())->toBe($whole)
        ->and($verdict->trees())->toEqual(TreeVerdicts::of($failed));
})->with([
    'its new code passed' => [NewCodeVerdicts::of($newCode(MutantJudgement::Killed)), Failures::none(), Judgement::Passed],
    'its new code failed' => [NewCodeVerdicts::of($newCode(MutantJudgement::Survived)), Failures::none(), Judgement::Failed],
    'a failure no floor decides' => [NewCodeVerdicts::none(), Failures::of(Failure::that('A stale ignore.')), Judgement::Failed],
]);

it('carries a kill matrix, one of first killers with no coverage until the run gives one', function () use ($passed): void {
    $matrix = KillMatrix::of(MatrixKind::Full, CoverageMap::empty());
    $verdict = Verdict::of(TreeVerdicts::of($passed));

    expect($verdict->matrix())->toEqual(KillMatrix::none())
        ->and($verdict->withMatrix($matrix)->matrix())->toBe($matrix)
        ->and($verdict->withMatrix($matrix)->cutShort()->matrix())->toBe($matrix);
});

it('fails when any tree or new-code set failed, or anything else did, and passes otherwise', function (
    TreeVerdicts $trees,
    NewCodeVerdicts $newCode,
    Failures $failures,
    Judgement $whole,
): void {
    expect(Verdict::of($trees)->withNewCode($newCode)->withFailures($failures)->judgement())->toBe($whole);
})->with([
    'no tree' => [TreeVerdicts::none(), NewCodeVerdicts::none(), Failures::none(), Judgement::Passed],
    'every tree passed' => [TreeVerdicts::of($passed, $passed), NewCodeVerdicts::none(), Failures::none(), Judgement::Passed],
    'the last tree failed' => [TreeVerdicts::of($passed, $failed), NewCodeVerdicts::none(), Failures::none(), Judgement::Failed],
    'the first tree failed' => [TreeVerdicts::of($failed, $passed), NewCodeVerdicts::none(), Failures::none(), Judgement::Failed],
    'the new code passed' => [TreeVerdicts::of($passed), NewCodeVerdicts::of($newCode(MutantJudgement::Killed)), Failures::none(), Judgement::Passed],
    'the new code failed' => [TreeVerdicts::of($passed), NewCodeVerdicts::of($newCode(MutantJudgement::Survived)), Failures::none(), Judgement::Failed],
    'a failure no floor decides' => [TreeVerdicts::of($passed), NewCodeVerdicts::none(), Failures::of(Failure::that('A stale ignore.')), Judgement::Failed],
]);
