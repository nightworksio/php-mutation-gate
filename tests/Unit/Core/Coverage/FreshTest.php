<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\Fresh;

it('stands for coverage a run collects itself', function (): void {
    expect(Fresh::coverage())->toBeInstanceOf(Fresh::class);
});
