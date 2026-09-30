<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Detached;

it('stands for a run with no ref of its own', function (): void {
    expect(Detached::head())->toBeInstanceOf(Detached::class);
});
