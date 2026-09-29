<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('stands for a duration nothing measured', function (): void {
    expect(Unmeasured::duration())->toBeInstanceOf(Unmeasured::class);
});
