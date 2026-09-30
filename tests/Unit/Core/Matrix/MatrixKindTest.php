<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\Outcome;

it('spells each kind of matrix as the reports write it', function (): void {
    expect(array_map(static fn(MatrixKind $kind): string => $kind->value, MatrixKind::cases()))->toBe(['first-killer', 'full']);
});

it('spells each outcome as the reports write it, and knows which ran to an answer', function (): void {
    expect(array_map(static fn(Outcome $outcome): array => [$outcome->value, $outcome->ran()], Outcome::cases()))->toBe([
        ['killed', true],
        ['passed', true],
        ['not-run', false],
        ['unknown', false],
    ]);
});
