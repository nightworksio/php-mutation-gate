<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\Filter;

it('holds its pattern', function (): void {
    expect(Filter::matching('KernelTest|BootTest')->pattern())->toBe('KernelTest|BootTest');
});
