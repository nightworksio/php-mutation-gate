<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;

it('says why a matrix is not full, in the sentence the tests report prints', function (NotFull $why, string $sentence): void {
    expect($why->sentence())->toBe($sentence);
})->with([
    [NotFull::FirstKillers, 'Needs a full kill matrix: run `mutation-gate run --kill-matrix=full`.'],
    [NotFull::Infection, 'Infection cannot produce a full kill matrix: it stops each mutant at its first failing test.'],
]);

it('holds first killers because the run did not ask, until told its runner cannot do more', function (): void {
    $matrix = KillMatrix::of(MatrixKind::FirstKiller, CoverageMap::empty());

    expect($matrix->whyNotFull())->toBe(NotFull::FirstKillers)
        ->and(KillMatrix::none()->whyNotFull())->toBe(NotFull::FirstKillers)
        ->and($matrix->cannotBeFull(NotFull::Infection)->whyNotFull())->toBe(NotFull::Infection)
        ->and($matrix->cannotBeFull(NotFull::Infection)->kind())->toBe(MatrixKind::FirstKiller);
});
