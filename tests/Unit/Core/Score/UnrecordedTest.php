<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\Unrecorded;

it('stands for a tree the baseline holds no floor for', function (): void {
    expect(Unrecorded::floor())->toBeInstanceOf(Unrecorded::class);
});
