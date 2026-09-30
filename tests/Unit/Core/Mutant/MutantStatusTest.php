<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

it('spells each status as the reports and the ledger write it', function (): void {
    expect(array_map(static fn(MutantStatus $status): string => $status->value, MutantStatus::cases()))
        ->toBe(['killed', 'survived', 'uncovered', 'timed-out', 'errored', 'unjudged', 'ignored-by-marker', 'skipped']);
});
