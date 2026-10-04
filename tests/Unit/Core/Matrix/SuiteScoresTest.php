<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScore;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScores;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuite;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Killings;

$suites = Killings::suites(...);

/** The verdict with its matrix grouped into these suites. */
$grouped = static fn(Verdict $verdict, DeclaredSuites $suites): Verdict => $verdict->withMatrix(
    $verdict->matrix()->grouping($suites),
);

/** Each suite's name, covered, killed, score in hundredths and exactness. */
$summary = static fn(SuiteScores $scores): array => array_map(
    static fn(SuiteScore $score): array => [
        $score->suite(),
        $score->covered(),
        $score->killed(),
        $score->score() instanceof Score ? $score->score()->hundredths() : 'nothing to mutate',
        $score->isExact(),
    ],
    iterator_to_array($scores, preserve_keys: false),
);

it('scores what each suite alone kills, a lower bound from first killers and exact under a full matrix', function (
    MatrixKind $kind,
) use ($suites, $grouped, $summary): void {
    $scores = SuiteScores::of($grouped(Killings::verdict($kind), $suites()));

    expect($summary($scores))->toBe([
        ['unit', 3, 2, 6_666, $kind === MatrixKind::Full],
        ['feature', 2, 1, 5_000, $kind === MatrixKind::Full],
    ])
        ->and($scores->arePlaced())->toBeTrue();
})->with([MatrixKind::FirstKiller, MatrixKind::Full]);

it('counts no mutant a static analyser killed, which no test of a suite has a known outcome for', function () use (
    $suites,
    $grouped,
    $summary,
): void {
    $scores = SuiteScores::of($grouped(Killings::verdict(MatrixKind::Full, MutantStatus::KilledByStaticAnalysis), $suites()));

    expect($summary($scores)[1])->toBe(['feature', 2, 1, 5_000, true]);
});

it('holds a test by a directory less what the suite excludes, and scores a suite that covers nothing as nothing to mutate', function () use (
    $grouped,
    $summary,
): void {
    $suites = DeclaredSuites::of(
        DeclaredSuite::named('all', Paths::none(), Paths::of(Path::of('tests/BTest.php')), SuiteDirectory::of(Path::of('tests'), '')),
        DeclaredSuite::named('none', Paths::none(), Paths::none(), SuiteDirectory::of(Path::of('spec'), '')),
    );

    expect($summary(SuiteScores::of($grouped(Killings::verdict(MatrixKind::FirstKiller), $suites))))->toBe([
        ['all', 4, 3, 7_500, false],
        ['none', 0, 0, 'nothing to mutate', false],
    ]);
});

it('counts a held unit\'s mutants only for its group\'s tests', function () use ($grouped, $summary): void {
    $suites = DeclaredSuites::of(
        DeclaredSuite::named('a', Paths::of(Path::of('tests/ATest.php')), Paths::none()),
        DeclaredSuite::named('c', Paths::of(Path::of('tests/CTest.php')), Paths::none()),
    );

    expect($summary(SuiteScores::of($grouped(Killings::heldVerdict(), $suites))))->toBe([
        ['a', 1, 0, 0, true],
        ['c', 0, 0, 'nothing to mutate', true],
    ]);
});

it('scores no suite where the project declares none, and says a run whose tests the runner named none is unplaced', function () use (
    $suites,
): void {
    $unnamed = Killings::verdict(MatrixKind::FirstKiller);
    $unnamed = $unnamed->withMatrix(KillMatrix::of(MatrixKind::FirstKiller, $unnamed->matrix()->coverage())->grouping($suites()));

    expect(SuiteScores::of(Killings::verdict(MatrixKind::FirstKiller)))->toHaveCount(0)
        ->and(SuiteScores::of($unnamed)->arePlaced())->toBeFalse()
        ->and(iterator_to_array(SuiteScores::of($unnamed), preserve_keys: false)[0]->covered())->toBe(0);
});

it('counts for no suite a mutant the score leaves out, as a survivor the config ignores', function () use ($summary): void {
    $suited = Killings::suited(MatrixKind::Full);
    $ignored = JudgedMutant::of(
        Mutant::of(
            Killings::mutantAt(3),
            'native-3',
            Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
            Mutation::of('Plus', MutatorFamily::Arithmetic, "-a3\n+b3\n"),
            MutantStatus::Survived,
            Unmeasured::duration(),
        ),
        MutantJudgement::Ignored,
    );
    $tree = TreeVerdict::judged(
        Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::of($ignored),
        Uncovered::Count,
    );

    expect($summary(SuiteScores::of(Verdict::of(TreeVerdicts::of($tree))->withMatrix($suited->matrix()))))->toBe([
        ['unit', 0, 0, 'nothing to mutate', true],
        ['feature', 0, 0, 'nothing to mutate', true],
    ]);
});
