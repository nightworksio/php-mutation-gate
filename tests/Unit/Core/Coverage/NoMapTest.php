<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\NoMap;

it('is the one absence of a map', function (): void {
    expect(NoMap::toRead())->toEqual(NoMap::toRead())
        ->and(NoMap::toRead())->toBeInstanceOf(NoMap::class);
});
