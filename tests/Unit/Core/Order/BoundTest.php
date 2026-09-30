<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Order\Bound;

it('holds how many a history keeps at most', function (): void {
    expect(Bound::atMost(20_000)->count())->toBe(20_000);
});
