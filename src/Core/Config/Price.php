<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Fields;

/** `costs.perRunnerMinute`: what a minute of a CI runner costs, which the cost report prices time with (ADR-0016). */
final readonly class Price
{
    private function __construct(private float $amount, private string $currency)
    {
    }

    public static function read(Fields $read): self
    {
        return new self($read->float('amount'), $read->string('currency'));
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }
}
