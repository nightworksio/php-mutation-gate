<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Verdict\Origin;

it('spells where each result came from as the reports write it', function (): void {
    expect(array_map(static fn(Origin $origin): string => $origin->value, Origin::cases()))
        ->toBe(['run', 'proved', 'carried']);
});
