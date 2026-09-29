<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Plan\ShardId;

it('holds its number', function (): void {
    expect(ShardId::of(3)->number())->toBe(3);
});
