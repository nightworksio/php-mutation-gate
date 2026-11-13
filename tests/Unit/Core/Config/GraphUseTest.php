<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\GraphUse;

it('loosens what a run reaches only where it narrows', function (): void {
    expect(array_map(static fn(GraphUse $use): bool => $use->isLoose(), GraphUse::cases()))->toBe([false, true, false, false]);
});

it('adds to quick rounds wherever it is on', function (): void {
    expect(array_map(static fn(GraphUse $use): bool => $use->addsToQuickRounds(), GraphUse::cases()))->toBe([true, true, true, false]);
});
