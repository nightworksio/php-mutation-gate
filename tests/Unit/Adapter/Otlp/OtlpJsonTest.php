<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Otlp\OtlpJson;
use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Telemetry\DataPoint;
use NightWorksIO\MutationGate\Core\Telemetry\Metric;
use NightWorksIO\MutationGate\Core\Telemetry\MetricKind;
use NightWorksIO\MutationGate\Core\Telemetry\Span;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Moment;

it('writes spans by OTLP\'s JSON mapping: times as decimal strings of nanoseconds, ids as lowercase hex', function (): void {
    $plan = Span::of('plan', 'a1b2c3d4e5f60718', '', Phase::of(Moment::at('2026-09-30T11:50:00Z'), Seconds::of(40.25)), ['cicd.pipeline.run.id' => 'github:1/1']);
    $mutate = Span::of('mutate', '0011223344556677', 'a1b2c3d4e5f60718', Phase::of(Moment::at('2026-09-30T11:51:00Z'), Seconds::of(1.0)), ['mutation_gate.shard' => 2]);
    $json = OtlpJson::traces('0123456789abcdef0123456789abcdef', [$plan, $mutate], ['service.name' => 'mutation-gate']);

    expect(Decoded::at($json, 'resourceSpans', 0, 'resource'))->toBe(['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'mutation-gate']]]])
        ->and(Decoded::at($json, 'resourceSpans', 0, 'scopeSpans', 0, 'scope'))->toBe(['name' => 'mutation-gate'])
        ->and(Decoded::at($json, 'resourceSpans', 0, 'scopeSpans', 0, 'spans'))->toBe([
            [
                'traceId' => '0123456789abcdef0123456789abcdef',
                'spanId' => 'a1b2c3d4e5f60718',
                'name' => 'plan',
                'kind' => 1,
                'startTimeUnixNano' => '1790769000000000000',
                'endTimeUnixNano' => '1790769040250000000',
                'attributes' => [['key' => 'cicd.pipeline.run.id', 'value' => ['stringValue' => 'github:1/1']]],
            ],
            [
                'traceId' => '0123456789abcdef0123456789abcdef',
                'spanId' => '0011223344556677',
                'parentSpanId' => 'a1b2c3d4e5f60718',
                'name' => 'mutate',
                'kind' => 1,
                'startTimeUnixNano' => '1790769060000000000',
                'endTimeUnixNano' => '1790769061000000000',
                'attributes' => [['key' => 'mutation_gate.shard', 'value' => ['intValue' => '2']]],
            ],
        ]);
});

it('writes a gauge\'s points as doubles and a sum\'s as 64-bit integers, this run\'s alone, stamped now', function (): void {
    $json = OtlpJson::metrics(
        [
            Metric::of('mutation_gate.score', '%', MetricKind::Gauge, [DataPoint::of(37.5, ['mutation_gate.tree' => 'src'])]),
            Metric::of('mutation_gate.mutants', '1', MetricKind::Sum, [DataPoint::of(3, ['mutation_gate.status' => 'survived'])]),
        ],
        ['service.name' => 'mutation-gate'],
        Moment::at('2026-09-30T12:00:00Z'),
    );

    expect(Decoded::at($json, 'resourceMetrics', 0, 'scopeMetrics', 0, 'metrics'))->toBe([
        [
            'name' => 'mutation_gate.score',
            'unit' => '%',
            'gauge' => ['dataPoints' => [[
                'asDouble' => 37.5,
                'timeUnixNano' => '1790769600000000000',
                'attributes' => [['key' => 'mutation_gate.tree', 'value' => ['stringValue' => 'src']]],
            ]]],
        ],
        [
            'name' => 'mutation_gate.mutants',
            'unit' => '1',
            'sum' => ['aggregationTemporality' => 1, 'isMonotonic' => true, 'dataPoints' => [[
                'asInt' => '3',
                'timeUnixNano' => '1790769600000000000',
                'attributes' => [['key' => 'mutation_gate.status', 'value' => ['stringValue' => 'survived']]],
            ]]],
        ],
    ]);
});
