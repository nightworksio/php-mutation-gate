<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `shards.pull`: how a shard finds its work (ADR-0134). */
enum ShardPull: string
{
    /** Steals: no service's claims pass the gate's race test. */
    case Auto = 'auto';

    /** Each item is claimed by an artifact of its own name before a shard runs it. */
    case Claim = 'claim';

    /** A shard takes items from the back of the longest list left, skipping those a done marker names. */
    case Steal = 'steal';

    /** Each shard runs its own planned part. */
    case Off = 'off';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Auto => 'Shards::pullingAuto()',
            self::Claim => 'Shards::pullingByClaims()',
            self::Steal => 'Shards::pullingBySteals()',
            self::Off => 'Shards::owningTheirParts()',
        };
    }

    /** Whether the plan cuts its work into items that shards pull. */
    public function pulls(): bool
    {
        return $this !== self::Off;
    }

    /** How a shard pulls: `auto` steals. */
    public function resolved(): self
    {
        return $this === self::Auto ? self::Steal : $this;
    }
}
