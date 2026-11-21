<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Process\Lineage;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Runner\Signal;
use NightWorksIO\MutationGate\Core\Written;

use function pcntl_waitpid;

/**
 * The analyser's warm-up the plan began (see PlannedWarmUp): running in a
 * child of the plan's, or made already; what it left taken once, for a plan
 * that holds a shard, or dropped, for one that holds none or none at all.
 *
 * @internal
 */
final class WarmingUp
{
    private bool $done = false;

    /** @param Written|Missing|CannotJudge|NotGiven $made what a warm-up made already left; none for a child's */
    private function __construct(
        private readonly int $child,
        private readonly Directory $project,
        private readonly Path $file,
        private readonly Written|Missing|CannotJudge|NotGiven $made,
    ) {
    }

    /** A warm-up running in this child, which leaves how long it took in this file of the project, or none. */
    public static function in(int $child, Directory $project, Path $left): self
    {
        return new self($child, $project, $left, NotGiven::value());
    }

    /** A warm-up made already, and what it left in this file of the project. */
    public static function ended(Written|Missing|CannotJudge $left, Directory $project, Path $file): self
    {
        return new self(0, $project, $file, $left);
    }

    /** What a planning made, the warm-up it began early dropped where it made no plan. */
    public static function settled(self|NotGiven $warming, PlanMade|CannotJudge $planned): PlanMade|CannotJudge
    {
        if ($warming instanceof self && ! $planned instanceof PlanMade) {
            $warming->dropped();
        }

        return $planned;
    }

    /** What the warm-up left for this plan, once it ended; dropped, where the plan holds no shard. */
    public function leftFor(Plan $plan): Written|Missing|CannotJudge
    {
        if (count($plan) === 0) {
            return $this->dropped();
        }

        $this->waited();

        return match (true) {
            ! $this->made instanceof NotGiven => $this->made,
            $this->project->read($this->file) instanceof Contents => Written::to($this->file->value()),
            default => Missing::at($this->file),
        };
    }

    /** The warm-up stopped where it still runs, and nothing it left kept. */
    public function dropped(): Missing|CannotJudge
    {
        if (! $this->done) {
            Lineage::spared()->signal($this->child, Signal::Kill);
        }

        $this->waited();

        return $this->project->remove($this->file);
    }

    /** The child waited for, once. */
    private function waited(): void
    {
        if ($this->child > 0 && ! $this->done) {
            pcntl_waitpid($this->child, $status);
        }

        $this->done = true;
    }
}
