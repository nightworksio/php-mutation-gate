<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Unreported;

it('stands for a line the runner does not report', function (): void {
    expect(Unreported::line())->toBeInstanceOf(Unreported::class);
});
