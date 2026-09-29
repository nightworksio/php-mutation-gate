<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('stands for no limit on time', function (): void {
    expect(Unlimited::time())->toBeInstanceOf(Unlimited::class);
});
