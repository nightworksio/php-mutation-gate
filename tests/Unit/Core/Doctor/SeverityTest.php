<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Severity;

it('says each severity as a person reads it', function (): void {
    expect(array_map(static fn(Severity $severity): string => $severity->said(), Severity::cases()))
        ->toBe(['will fail', 'slow', 'advice']);
});
