<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Reason;

it('holds the sentence a report prints beside the mutant', function (): void {
    expect(Reason::that('Pest cannot name LegacySpec::decrements in a filter.')->text())
        ->toBe('Pest cannot name LegacySpec::decrements in a filter.');
});
