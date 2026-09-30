<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\SymbolKind;

it('follows constants, cases, properties and plain functions\' parameters, and nothing else', function (): void {
    $followable = array_map(
        static fn(SymbolKind $kind): string => $kind->value,
        array_values(array_filter(SymbolKind::cases(), static fn(SymbolKind $kind): bool => $kind->isFollowable())),
    );

    expect($followable)->toBe(['constant', 'enum case', 'static property', 'property', 'function parameter']);
});
