<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Uncovered;

it('spells each way of treating an uncovered mutant as the config writes it', function (): void {
    expect(array_map(static fn(Uncovered $mode): string => $mode->value, Uncovered::cases()))->toBe(['count', 'exclude']);
});
