<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Local\WindowsClass;

it('hands proc_nice the least value Windows reads as each class', function (): void {
    expect(array_map(static fn(WindowsClass $class): int => $class->nice(), WindowsClass::cases()))->toBe([0, 6, 10]);
});
