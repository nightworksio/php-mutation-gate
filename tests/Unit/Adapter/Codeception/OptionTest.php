<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Codeception\Option;

it('names each setting the gate tells its extension, as the extension reads it back', function (): void {
    expect(array_map(static fn(Option $option): string => $option->value, Option::cases()))
        ->toBe(['selected', 'in-group', 'matching', 'declared-in', 'coverage-map', 'listing-file', 'vendor-directory']);
});
