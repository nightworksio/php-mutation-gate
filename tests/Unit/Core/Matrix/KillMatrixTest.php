<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\Outcome;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

$first = TestId::of('MoneyTest::fits');
$second = TestId::of('MoneyTest::refuses');
$third = TestId::of('PriceTest::adds');

$judged = static function (MutantStatus $status, TestIds $killers, int $end = 7): JudgedMutant {
    $path = Path::of('src/Money.php');
    $mutant = Mutant::of(
        MutantId::hash($path, 'Plus', sprintf("-%s\n+x\n", $status->value), 7),
        'native',
        Location::of($path, Line::of(7), Line::of($end)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        $status,
        Unmeasured::duration(),
    )->killedBy($killers);

    return JudgedMutant::of($mutant, MutantJudgement::reported($status));
};

$coverage = static fn(): CoverageMap => CoverageMap::empty()
    ->covered(Path::of('src/Money.php'), Line::of(7), $first)
    ->covered(Path::of('src/Money.php'), Line::of(7), $second)
    ->covered(Path::of('src/Money.php'), Line::of(8), $third)
    ->timed($first, Seconds::of(0.5));

it('knows only the killers each record names where the run gave no matrix', function () use ($judged, $first): void {
    $killed = $judged(MutantStatus::Killed, TestIds::of($first));

    expect(KillMatrix::none()->kind())->toBe(MatrixKind::FirstKiller)
        ->and(KillMatrix::none()->coveredBy($killed))->toEqual(TestIds::of($first))
        ->and(KillMatrix::none()->names())->toEqual(TestNames::none())
        ->and(KillMatrix::none()->secondsOf($first))->toEqual(Unmeasured::duration());
});

it('covers a mutant by every test the map holds for any line it spans, and every killer', function () use ($judged, $coverage, $first, $second, $third): void {
    $matrix = KillMatrix::of(MatrixKind::Full, $coverage());
    $killer = TestId::of('CartTest::totals');

    expect($matrix->kind())->toBe(MatrixKind::Full)
        ->and($matrix->coveredBy($judged(MutantStatus::Survived, TestIds::none())))->toEqual(TestIds::of($first, $second))
        ->and($matrix->coveredBy($judged(MutantStatus::Survived, TestIds::none(), end: 8)))->toEqual(TestIds::of($first, $second, $third))
        ->and($matrix->coveredBy($judged(MutantStatus::Killed, TestIds::of($killer))))->toEqual(TestIds::of($first, $second, $killer))
        ->and($matrix->secondsOf($first))->toEqual(Seconds::of(0.5));
});

it('covers a carried mutant by the tests its proof names', function () use ($judged, $coverage, $third): void {
    $carried = $judged(MutantStatus::Survived, TestIds::none());
    $matrix = KillMatrix::of(MatrixKind::FirstKiller, $coverage())->carried($carried->mutant()->id(), TestIds::of($third));

    expect($matrix->coveredBy($carried))->toEqual(TestIds::of($third));
});

it('says a test passed with a survivor, and killed a mutant it killed', function () use ($judged, $coverage, $first, $second): void {
    $matrix = KillMatrix::of(MatrixKind::FirstKiller, $coverage());
    $killed = $judged(MutantStatus::Killed, TestIds::of($first));
    $survivor = $judged(MutantStatus::Survived, TestIds::none());

    expect($matrix->outcome($survivor, $first))->toBe(Outcome::Passed)
        ->and($matrix->outcome($killed, $first))->toBe(Outcome::Killed)
        ->and($matrix->outcome($judged(MutantStatus::Errored, TestIds::of($second)), $second))->toBe(Outcome::Killed);
});

it('says the other covering tests of a killed mutant did not run, or passed under a full matrix', function () use ($judged, $coverage, $first, $second): void {
    $killed = $judged(MutantStatus::Killed, TestIds::of($first));

    expect(KillMatrix::of(MatrixKind::FirstKiller, $coverage())->outcome($killed, $second))->toBe(Outcome::NotRun)
        ->and(KillMatrix::of(MatrixKind::Full, $coverage())->outcome($killed, $second))->toBe(Outcome::Passed);
});

it('does not know what a test did with a timeout, a mutant out of memory, a flaky mutant, an unknown killer or moved coverage', function () use ($judged, $coverage, $first): void {
    $matrix = KillMatrix::of(MatrixKind::Full, $coverage());
    $survivor = $judged(MutantStatus::Survived, TestIds::none());
    $flaky = JudgedMutant::of($survivor->mutant(), MutantJudgement::Flaky);

    expect($matrix->outcome($judged(MutantStatus::TimedOut, TestIds::of($first)), $first))->toBe(Outcome::Unknown)
        ->and($matrix->outcome($judged(MutantStatus::OutOfMemory, TestIds::none()), $first))->toBe(Outcome::Unknown)
        ->and($matrix->outcome($flaky, $first))->toBe(Outcome::Unknown)
        ->and($matrix->outcome($judged(MutantStatus::Killed, TestIds::none()), $first))->toBe(Outcome::Unknown)
        ->and($matrix->moved($survivor->mutant()->id())->outcome($survivor, $first))->toBe(Outcome::Unknown)
        ->and($matrix->outcome($survivor, $first))->toBe(Outcome::Passed);
});

it('says no test ran a mutant the run never ran', function (MutantStatus $status) use ($judged, $coverage, $first): void {
    expect(KillMatrix::of(MatrixKind::Full, $coverage())->outcome($judged($status, TestIds::none()), $first))->toBe(Outcome::NotRun);
})->with([
    MutantStatus::KilledByStaticAnalysis,
    MutantStatus::Uncovered,
    MutantStatus::Unjudged,
    MutantStatus::IgnoredByMarker,
    MutantStatus::Skipped,
]);

it('keeps the names the runner gives its tests', function () use ($coverage, $first): void {
    $names = TestNames::none()->with($first, TestName::in(Path::of('tests/Unit/MoneyTest.php'), 'it fits'));

    expect(KillMatrix::of(MatrixKind::FirstKiller, $coverage())->named($names)->names())->toBe($names);
});

it('says a test outside the ones that judge a held mutant never ran with it', function () use ($judged, $coverage, $first, $second): void {
    $matrix = KillMatrix::of(MatrixKind::Full, $coverage());
    $held = $judged(MutantStatus::Killed, TestIds::of($first))->judgedBy(TestIds::of($first));
    $open = $judged(MutantStatus::Killed, TestIds::of($first));

    expect($matrix->judges($held, $first))->toBeTrue()
        ->and($matrix->judges($held, $second))->toBeFalse()
        ->and($matrix->judges($open, $second))->toBeTrue()
        ->and($matrix->outcome($held, $second))->toBe(Outcome::NotRun)
        ->and($matrix->outcome($held, $first))->toBe(Outcome::Killed)
        ->and($matrix->coverage())->toEqual($coverage());
});
