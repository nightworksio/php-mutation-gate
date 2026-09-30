<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\ProvingRange;

it('asks about twenty commits at most', function (): void {
    expect(ProvingRange::standard()->farthest())->toBe(20);
});
