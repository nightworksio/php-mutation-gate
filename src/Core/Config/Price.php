<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** What a runner-minute costs, in a currency the team names (ADR-0016): `costs.perRunnerMinute`. */
final readonly class Price
{
    private function __construct(private int|float $amount, private string $currency)
    {
    }

    public static function of(int|float $amount, string $currency): self
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

    public function written(): Json
    {
        return Json::object(Member::of('amount', $this->amount))->with(Member::of('currency', $this->currency));
    }
}
