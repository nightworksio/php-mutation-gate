<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Forever;

it('is the one day that never comes, whoever names it', function (): void {
    expect(Forever::of())->toEqual(Forever::of())
        ->and(Forever::of())->toBeInstanceOf(Forever::class);
});
