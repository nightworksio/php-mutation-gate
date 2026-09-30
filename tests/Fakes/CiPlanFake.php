<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\CiPlan;

/** A CI whose job was started as one shard of a run it describes, and which keeps the plans handed to it. */
final class CiPlanFake implements CiPlan
{
    /** @var list<Plan> */
    public private(set) array $published = [];

    private Paths $definitions;

    public function __construct(private readonly ShardId $thisJob, private readonly RunOn|CannotTell $run)
    {
        $this->definitions = Paths::none();
    }

    /** This fake, where these CI definitions run the gate. */
    public function runBy(Paths $definitions): self
    {
        $this->definitions = $definitions;

        return $this;
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

    public function runOn(): RunOn|CannotTell
    {
        return $this->run;
    }

    public function definitions(): Paths
    {
        return $this->definitions;
    }

    public static function withheld(): Withheld
    {
        return Withheld::of('FAKE_CI_TOKEN');
    }
}
