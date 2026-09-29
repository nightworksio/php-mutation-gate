<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\CiPlan;

/** A CI whose job was started as one shard, and which keeps the plans handed to it. */
final class CiPlanFake implements CiPlan
{
    /** @var list<Plan> */
    public private(set) array $published = [];

    public function __construct(private readonly ShardId $thisJob)
    {
    }

    public function publish(Plan $plan): Written
    {
        $this->published[] = $plan;

        return Written::to('memory');
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        $shard = $plan->shard($this->thisJob);

        return $shard instanceof CannotJudge ? $shard : $shard->id();
    }
}
