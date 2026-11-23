<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * The shares of a shard job's time (ADR-0303, ADR-0332): `shards.fill` and
 * `shards.confirm`.
 */
final readonly class ShardShares implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /**
     * `shards.fill`: the share of its stop the cut fills a job that runs its
     * own tail with, above 0 and at most 1; 0.92 (ADR-0303).
     */
    public static function fill(float $share): self
    {
        return new self(Json::at('shards.fill', $share));
    }

    /**
     * `shards.confirm`: the share of the time a first-wave job has left that
     * its run again of doubtful kills may take, above 0 and at most 1; 0.1
     * (ADR-0332).
     */
    public static function confirm(float $share): self
    {
        return new self(Json::at('shards.confirm', $share));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
