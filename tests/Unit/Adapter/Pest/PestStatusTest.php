<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

it('is the gate\'s status for each of Pest\'s, and none is one the gate did not judge', function (): void {
    expect(array_map(static fn(PestStatus $status): MutantStatus => $status->status(), PestStatus::cases()))->toBe([
        MutantStatus::Killed,
        MutantStatus::Survived,
        MutantStatus::Uncovered,
        MutantStatus::TimedOut,
        MutantStatus::Unjudged,
    ]);
});

it('reads and names each status as Pest\'s summary line does, where a mutant never run is pending', function (): void {
    expect(array_map(static fn(PestStatus $status): string => $status->onSummary(), PestStatus::cases()))
        ->toBe(['tested', 'untested', 'uncovered', 'timeout', 'pending'])
        ->and(array_map(PestStatus::fromSummary(...), ['tested', 'untested', 'uncovered', 'timeout', 'pending']))
        ->toBe(PestStatus::cases());
});
