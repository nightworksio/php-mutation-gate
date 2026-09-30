<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;

it('spells each family as the reports and the config write it', function (): void {
    expect(array_map(static fn(MutatorFamily $family): string => $family->value, MutatorFamily::cases()))
        ->toBe(['boundary', 'condition', 'logical', 'arithmetic', 'return-value', 'removed-call', 'literal', 'collection', 'exception', 'unwrap', 'visibility', 'none', 'unknown']);
});

it('names a kind of change for every family but none and unknown', function (): void {
    expect(array_map(
        static fn(MutatorFamily $family): string => $family->value,
        array_values(array_filter(MutatorFamily::cases(), static fn(MutatorFamily $family): bool => ! $family->isKind())),
    ))->toBe(['none', 'unknown']);
});
