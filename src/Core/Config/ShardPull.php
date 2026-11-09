<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function in_array;

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

    /**
     * The services, by the server URL a run reports, on which the gate's own
     * CI's race test shows an artifact name won by exactly one job.
     */
    public const array RACED = ['https://github.com'];

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

    /** How a shard on the service at this server URL pulls: `auto` claims where the race test passed there. */
    public function on(string $server): self
    {
        return match (true) {
            $this !== self::Auto => $this,
            in_array($server, self::RACED, true) => self::Claim,
            default => self::Steal,
        };
    }
}
