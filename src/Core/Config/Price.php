<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Number;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

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

    /** @return Section<self> */
    public static function shape(): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $amount = Field::required('amount', Number::atLeast(0), $judges);
        $currency = Field::required('currency', Text::of('a currency, such as EUR'), $judges);

        return Section::of(
            static function (Node $price) use ($amount, $currency): self|Invalid {
                $much = $amount->read($price);
                $in = $currency->read($price);

                return Reading::built(static fn(): self => new self($much->must(), $in->must()), $much, $in);
            },
            $amount,
            $currency,
        );
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
        return Json::object()->with('amount', $this->amount)->with('currency', $this->currency);
    }
}
