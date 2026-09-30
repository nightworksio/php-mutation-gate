<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Telemetry\DataPoint;
use NightWorksIO\MutationGate\Core\Telemetry\Metric;
use NightWorksIO\MutationGate\Core\Telemetry\MetricKind;

it('holds its name, unit, kind and points', function (): void {
    $point = DataPoint::of(83.5, ['mutation_gate.tree' => 'src']);
    $metric = Metric::of('mutation_gate.score', '%', MetricKind::Gauge, [$point]);

    expect($metric->name())->toBe('mutation_gate.score')
        ->and($metric->unit())->toBe('%')
        ->and($metric->kind())->toBe(MetricKind::Gauge)
        ->and($metric->points())->toBe([$point]);
});
