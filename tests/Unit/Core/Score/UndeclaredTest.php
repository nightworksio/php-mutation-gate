<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\Undeclared;

it('stands for a floor nobody declared', function (): void {
    expect(Undeclared::floor())->toBeInstanceOf(Undeclared::class);
});
