<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Telemetry\DataPoint;

it('holds a whole or a fractional value, and the attributes that tell it apart', function (): void {
    expect(DataPoint::of(3, ['mutation_gate.status' => 'survived'])->value())->toBe(3)
        ->and(DataPoint::of(0.5, [])->value())->toBe(0.5)
        ->and(DataPoint::of(3, ['mutation_gate.status' => 'survived'])->attributes())->toBe(['mutation_gate.status' => 'survived']);
});
