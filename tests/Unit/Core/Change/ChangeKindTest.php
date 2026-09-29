<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\ChangeKind;

it('spells each kind of change', function (): void {
    expect(array_map(static fn(ChangeKind $kind): string => $kind->value, ChangeKind::cases()))->toBe(['added', 'modified', 'deleted', 'renamed']);
});
