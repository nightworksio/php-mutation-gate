<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * How a learned `timeouts.multiple` is read from the kills a ledger holds:
 * the percentile it takes and the least and most it allows (ADR-0073):
 * `timeouts.learned`.
 */
final readonly class LearnedTimeouts implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `timeouts.learned.percentile`: the share of a ledger's kills a learned multiple covers, 0.99 by default. */
    public static function percentile(int|float $share): self
    {
        return new self(Json::at('timeouts.learned.percentile', $share));
    }

    /** `timeouts.learned.least`: the least a learned multiple is, 2 by default. */
    public static function least(int|float $multiple): self
    {
        return new self(Json::at('timeouts.learned.least', $multiple));
    }

    /** `timeouts.learned.most`: the most a learned multiple is, 10 by default. */
    public static function most(int|float $multiple): self
    {
        return new self(Json::at('timeouts.learned.most', $multiple));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
