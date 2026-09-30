<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Telemetry;

/** One metric a verdict emits: its name, unit and kind, and its points. */
final readonly class Metric
{
    /** @param list<DataPoint> $points */
    private function __construct(
        private string $name,
        private string $unit,
        private MetricKind $kind,
        private array $points,
    ) {
    }

    /** @param list<DataPoint> $points */
    public static function of(string $name, string $unit, MetricKind $kind, array $points): self
    {
        return new self($name, $unit, $kind, $points);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Its unit as UCUM writes it: `%`, `s`, `min` or `1`. */
    public function unit(): string
    {
        return $this->unit;
    }

    public function kind(): MetricKind
    {
        return $this->kind;
    }

    /** @return list<DataPoint> */
    public function points(): array
    {
        return $this->points;
    }
}
