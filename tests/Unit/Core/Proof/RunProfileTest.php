<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Test\SuiteName;

it('says how much of the matrix it records, whether it holds the security sets alone, and the one suite that judges', function (): void {
    $kind = RunProfile::standard()->recording(MatrixKind::Full)->securityOnly()->inSuite(SuiteName::of('unit'));

    expect([$kind->matrix(), $kind->isSecurityOnly(), $kind->suite()])->toEqual([MatrixKind::Full, true, SuiteName::of('unit')]);
});

it('is another kind where any of what it is of differs', function (RunProfile $one, RunProfile $other, bool $equal): void {
    expect([$one->equals($other), $other->equals($one)])->toBe([$equal, $equal]);
})->with([
    'the same' => [fn(): RunProfile => RunProfile::standard(), fn(): RunProfile => RunProfile::standard(), true],
    'the full matrix' => [fn(): RunProfile => RunProfile::standard(), fn(): RunProfile => RunProfile::standard()->recording(MatrixKind::Full), false],
    'the security sets alone' => [fn(): RunProfile => RunProfile::standard(), fn(): RunProfile => RunProfile::standard()->securityOnly(), false],
    'one suite' => [fn(): RunProfile => RunProfile::standard(), fn(): RunProfile => RunProfile::standard()->inSuite(SuiteName::of('unit')), false],
    'the same suite' => [
        fn(): RunProfile => RunProfile::standard()->inSuite(SuiteName::of('unit')),
        fn(): RunProfile => RunProfile::standard()->inSuite(SuiteName::of('unit')),
        true,
    ],
    'another suite' => [
        fn(): RunProfile => RunProfile::standard()->inSuite(SuiteName::of('unit')),
        fn(): RunProfile => RunProfile::standard()->inSuite(SuiteName::of('feature')),
        false,
    ],
]);
