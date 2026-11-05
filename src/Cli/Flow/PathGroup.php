<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\MatchedRules;
use NightWorksIO\MutationGate\Core\Plan\Shard;

/**
 * A group of a shard's units that the same `paths` entries match, as a shard of its own (ADR-0074).
 *
 * @internal
 */
final readonly class PathGroup
{
    private function __construct(private MatchedRules $rules, private Shard $shard)
    {
    }

    public static function of(MatchedRules $rules, Shard $shard): self
    {
        return new self($rules, $shard);
    }

    public function rules(): MatchedRules
    {
        return $this->rules;
    }

    public function shard(): Shard
    {
        return $this->shard;
    }
}
