<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Port\CiPlan;

/** The flows' CI, which cannot take the plan it is handed. */
final readonly class UnpublishedCi implements CiPlan
{
    public function __construct(private string $why)
    {
    }

    public function publish(Plan $plan): CannotJudge
    {
        return CannotJudge::because($this->why);
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return Flows::ci()->shard($plan);
    }

    public function runOn(): RunOn|CannotTell
    {
        return Flows::ci()->runOn();
    }

    public function definitions(): Paths
    {
        return Flows::ci()->definitions();
    }

    public function withheld(): Withheld
    {
        return Flows::ci()->withheld();
    }
}
