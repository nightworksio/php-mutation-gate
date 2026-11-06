<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\BuiltinCostModel;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** `costs.model`: what the plan expects each unit's work to cost (ADR-0006); `learned` where the config names none. */
final readonly class CostModel implements Setting
{
    private function __construct(private Json|string $choice)
    {
    }

    /** What the ledger learned each unit costs, and `costs.secondsPerLine` before it learned anything. */
    public static function learned(): self
    {
        return self::uses(BuiltinCostModel::Learned->value);
    }

    /** A cost model another extension registers by name, or a class, with its options. */
    public static function uses(string $model, Option ...$options): self
    {
        return new self(Option::choice($model, ...$options));
    }

    public function written(): Json
    {
        return Json::object(Member::of('costs', Json::object(Member::of('model', $this->choice))));
    }
}
