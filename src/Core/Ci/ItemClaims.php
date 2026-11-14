<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

/**
 * How a job of a run whose shards pull their work tells the others which
 * items it takes (ADR-0134): by claims, which one job wins, or by done
 * markers, which let two jobs run one item.
 *
 * @internal
 */
interface ItemClaims
{
    /** Whether this job takes the item: it claimed it, or no job marked it done; or why the service cannot tell. */
    public function take(ShardId $item): bool|CannotJudge;

    /** The item, marked done where the way of pulling marks what it finished; or why it could not be. */
    public function finish(ShardId $item): ShardId|CannotJudge;
}
