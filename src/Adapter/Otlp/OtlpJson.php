<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Otlp;

use DateTimeImmutable;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Telemetry\DataPoint;
use NightWorksIO\MutationGate\Core\Telemetry\Metric;
use NightWorksIO\MutationGate\Core\Telemetry\MetricKind;
use NightWorksIO\MutationGate\Core\Telemetry\Span;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * Spans and metrics as OTLP/HTTP JSON, by OpenTelemetry's JSON mapping: a
 * 64-bit integer, a time among them, as a decimal string, and trace and span
 * ids as lowercase hex (ADR-0016, decision 14).
 *
 * @phpstan-type KeyValue array{key: string, value: array{stringValue: string}|array{intValue: string}}
 */
final readonly class OtlpJson
{
    /** OTLP's span kind for work inside one service. */
    private const int INTERNAL = 1;

    /** OTLP's aggregation temporality for a count of this run's alone. */
    private const int DELTA = 1;

    /**
     * @param list<Span>            $spans
     * @param array<string, string> $resource the resource's attributes, by name
     */
    public static function traces(string $traceId, array $spans, array $resource): string
    {
        $written = [];

        foreach ($spans as $span) {
            $start = self::nanos($span->phase()->start()) + $span->phase()->after()->nanoseconds();
            $written[] = [
                'traceId' => $traceId,
                'spanId' => $span->id(),
                ...$span->parent() === '' ? [] : ['parentSpanId' => $span->parent()],
                'name' => $span->name(),
                'kind' => self::INTERNAL,
                'startTimeUnixNano' => sprintf('%d', $start),
                'endTimeUnixNano' => sprintf('%d', $start + $span->phase()->duration()->nanoseconds()),
                'attributes' => self::attributes($span->attributes()),
            ];
        }

        return JsonText::encode(['resourceSpans' => [[
            'resource' => ['attributes' => self::attributes($resource)],
            'scopeSpans' => [['scope' => ['name' => ThisPackage::NAME], 'spans' => $written]],
        ]]]);
    }

    /**
     * @param list<Metric>          $metrics
     * @param array<string, string> $resource the resource's attributes, by name
     */
    public static function metrics(array $metrics, array $resource, Instant $now): string
    {
        $time = sprintf('%d', self::nanos($now));
        $written = [];

        foreach ($metrics as $metric) {
            $points = [];

            foreach ($metric->points() as $point) {
                $points[] = [
                    ...self::value($point),
                    'timeUnixNano' => $time,
                    'attributes' => self::attributes($point->attributes()),
                ];
            }

            $written[] = [
                'name' => $metric->name(),
                'unit' => $metric->unit(),
                ...$metric->kind() === MetricKind::Gauge
                    ? ['gauge' => ['dataPoints' => $points]]
                    : ['sum' => [
                        'aggregationTemporality' => self::DELTA,
                        'isMonotonic' => true,
                        'dataPoints' => $points,
                    ]],
            ];
        }

        return JsonText::encode(['resourceMetrics' => [[
            'resource' => ['attributes' => self::attributes($resource)],
            'scopeMetrics' => [['scope' => ['name' => ThisPackage::NAME], 'metrics' => $written]],
        ]]]);
    }

    /** @return array{asInt: string}|array{asDouble: float} */
    private static function value(DataPoint $point): array
    {
        $value = $point->value();

        return is_int($value) ? ['asInt' => sprintf('%d', $value)] : ['asDouble' => $value];
    }

    /**
     * @param  array<string, string|int> $attributes
     * @return list<KeyValue>
     */
    private static function attributes(array $attributes): array
    {
        $written = [];

        foreach ($attributes as $key => $value) {
            $written[] = [
                'key' => $key,
                'value' => is_int($value) ? ['intValue' => sprintf('%d', $value)] : ['stringValue' => $value],
            ];
        }

        return $written;
    }

    private static function nanos(Instant $instant): int
    {
        $moment = $instant->moment();

        return $moment instanceof DateTimeImmutable ? $moment->getTimestamp() * Seconds::NANOSECONDS : 0;
    }
}
