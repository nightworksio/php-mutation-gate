<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * Which GitHub definition `init --ci=github` writes (ADR-0015 decision 15):
 * the one-step action, where a full run is estimated to fit one shard's
 * `shards.seconds`, or the reusable workflow, which shards it.
 */
enum GitHubWorkflow: string
{
    case Single = 'single';

    case Sharded = 'sharded';

    /** The one a full run of this estimate takes, where one shard holds this much. */
    public static function forEstimate(Seconds $estimate, Seconds $shard): self
    {
        return $estimate->seconds() <= $shard->seconds() ? self::Single : self::Sharded;
    }

    /** The check a branch protection rule requires for it. */
    public function check(): string
    {
        return match ($this) {
            self::Single => 'mutation testing',
            self::Sharded => Ci::none()->check(),
        };
    }

    /** What it is, as `init` says it. */
    public function said(): string
    {
        return match ($this) {
            self::Single => 'the one-step action',
            self::Sharded => 'the reusable workflow',
        };
    }
}
