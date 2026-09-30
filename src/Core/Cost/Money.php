<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function sprintf;

/** An amount of money in a team's own currency, as the cost section shows it: `2.80 EUR`. */
final readonly class Money
{
    private function __construct(private float $amount, private string $currency)
    {
    }

    public static function of(float $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** Two decimals and the currency as the team gave it. */
    public function text(): string
    {
        return sprintf('%.2f %s', $this->amount, $this->currency);
    }
}
