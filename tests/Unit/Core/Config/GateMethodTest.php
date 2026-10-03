<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Core\Config\GateMethod;

it('names only methods the PHP builder\'s Gate has', function (): void {
    $missing = array_values(array_filter(
        GateMethod::cases(),
        static fn(GateMethod $method): bool => ! method_exists(Gate::class, $method->value),
    ));

    expect($missing)->toBe([]);
});
