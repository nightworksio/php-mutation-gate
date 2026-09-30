<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Telemetry;

/** One value of a metric, with the attributes that tell it from the metric's others. */
final readonly class DataPoint
{
    /** @param array<string, string|int> $attributes by name */
    private function __construct(private int|float $value, private array $attributes)
    {
    }

    /** @param array<string, string|int> $attributes by name */
    public static function of(int|float $value, array $attributes): self
    {
        return new self($value, $attributes);
    }

    public function value(): int|float
    {
        return $this->value;
    }

    /** @return array<string, string|int> by name */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
