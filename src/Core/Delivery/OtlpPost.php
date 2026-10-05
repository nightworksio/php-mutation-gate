<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\NotGiven;

/** The OTLP/HTTP JSON a run leaves for `deliver` to export: the trace, for a timed run, and the metrics. */
final readonly class OtlpPost
{
    private function __construct(private string|NotGiven $traces, private string $metrics)
    {
    }

    public static function of(string|NotGiven $traces, string $metrics): self
    {
        return new self($traces, $metrics);
    }

    public function traces(): string|NotGiven
    {
        return $this->traces;
    }

    public function metrics(): string
    {
        return $this->metrics;
    }
}
