<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `shards.pull`: how a shard finds its work (ADR-0134). */
enum ShardPull: string
{
    /** Claims on a service whose claims passed the gate's race test, steals elsewhere. */
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

    /** How a shard pulls on a service whose race test shows each artifact name won by one job: `auto` claims. */
    public function whereRaced(): self
    {
        return $this === self::Auto ? self::Claim : $this;
    }

    /** How a shard pulls on a service no race test vouches for: `auto` steals. */
    public function whereUnraced(): self
    {
        return $this === self::Auto ? self::Steal : $this;
    }
}
