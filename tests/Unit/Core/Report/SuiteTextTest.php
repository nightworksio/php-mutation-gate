<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScore;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScores;
use NightWorksIO\MutationGate\Core\Report\SuiteText;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuite;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Tests\Support\Killings;

it('says what each suite alone kills, exactly under a full matrix', function (): void {
    expect(SuiteText::lines(SuiteScores::of(Killings::suited(MatrixKind::Full)), NotFull::FirstKillers))->toBe([
        'unit alone kills 66.66% of the 3 mutants its tests cover.',
        'feature alone kills 50.00% of the 2 mutants its tests cover.',
    ]);
});

it('says each score is at least what it is from first killers, and why it is a lower bound', function (NotFull $why, string $said): void {
    expect(SuiteText::lines(SuiteScores::of(Killings::suited(MatrixKind::FirstKiller)), $why))->toBe([
        'unit alone kills at least 66.66% of the 3 mutants its tests cover.',
        'feature alone kills at least 50.00% of the 2 mutants its tests cover.',
        $said,
    ]);
})->with([
    'first killers' => [NotFull::FirstKillers, 'Each is a lower bound, from first killers: `mutation-gate run --kill-matrix=full` makes it exact.'],
    'Infection' => [NotFull::Infection, 'Each is a lower bound: Infection stops each mutant at its first failing test.'],
]);

it('says a suite whose tests cover no mutant covers none', function (): void {
    expect(SuiteText::of(SuiteScore::of('browser', 0, 0, exact: true)))->toBe('browser covers no mutant.');
});

it('says no suite can be scored where the runner named no test', function (): void {
    expect(SuiteText::lines(SuiteScores::of(Killings::unplaced()), NotFull::FirstKillers))
        ->toBe(['The runner named no test, so no suite can be scored.'])
        ->and(SuiteText::unplaced())->toBe('The runner named no test, so no suite can be scored.');
});

it('shows the suites only where the config declares two or more, as one suite\'s score is the run\'s own', function (): void {
    $one = Killings::verdict(MatrixKind::Full);
    $one = $one->withMatrix($one->matrix()->grouping(DeclaredSuites::of(DeclaredSuite::named('all', Paths::none(), Paths::none()))));

    expect(SuiteText::shows(SuiteScores::of($one)))->toBeFalse()
        ->and(SuiteText::lines(SuiteScores::of($one), NotFull::FirstKillers))->toBe([])
        ->and(SuiteText::lines(SuiteScores::of(Killings::verdict(MatrixKind::Full)), NotFull::FirstKillers))->toBe([])
        ->and(SuiteText::shows(SuiteScores::of(Killings::suited(MatrixKind::Full))))->toBeTrue();
});

it('says a suite\'s name, which the PHPUnit config writes, as one plain line with no control character', function (): void {
    expect(SuiteText::of(SuiteScore::of("<script>alert(1)</script>|x\n\e[31mred", 2, 1, exact: true)))
        ->toBe('<script>alert(1)</script>|x [31mred alone kills 50.00% of the 2 mutants its tests cover.');
});
