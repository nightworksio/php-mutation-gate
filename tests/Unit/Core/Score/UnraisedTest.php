<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\Unraised;

it('stands for a score that raises no floor', function (): void {
    expect(Unraised::floor())->toBeInstanceOf(Unraised::class);
});
