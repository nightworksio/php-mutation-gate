<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

/**
 * How a runner behaves where the flows must know it: whether it reads each
 * `#[Holds]` as its test files load, whether a timeout's limit can be raised
 * for a retry (ADR-0008), which groups' test files every proof key reads
 * (ADR-0007), whether each shard pays a full opening run under coverage
 * (ADR-0006), whether it can record every test that kills a mutant, for
 * a full kill matrix (ADR-0014), and how many mutants it runs at once. The
 * flows ask the runner, never its name.
 */
final readonly class RunnerBehaviour
{
    private function __construct(
        private bool $holdsAsLoaded,
        private bool $raisesLimits,
        private Groups $readByEveryKey,
        private bool $opensEachShard,
        private NotFull $whyNotFull,
        private Processes $parallelism,
    ) {
    }

    /**
     * A runner that lists `#[Holds]` as groups, can raise a limit, has no
     * group every key reads, reuses the map the plan handed each shard, and
     * runs one mutant at a time.
     */
    public static function standard(): self
    {
        return new self(
            holdsAsLoaded: false,
            raisesLimits: true,
            readByEveryKey: Groups::none(),
            opensEachShard: false,
            whyNotFull: NotFull::FirstKillers,
            parallelism: Processes::single(),
        );
    }

    /** This behaviour, reading each `#[Holds]` as the runner loads its test files, where a group may never form. */
    public function holdingAsLoaded(): self
    {
        return clone($this, ['holdsAsLoaded' => true]);
    }

    /** This behaviour, where a timeout's limit cannot be raised, so a timeout is never run again. */
    public function raisingNoLimit(): self
    {
        return clone($this, ['raisesLimits' => false]);
    }

    /** This behaviour, where every proof key reads a group's test files, since every shard opens on them. */
    public function readingInEveryKey(Group $group): self
    {
        return clone($this, ['readByEveryKey' => $this->readByEveryKey->with($group)]);
    }

    /** This behaviour, where each shard pays a full opening run under coverage. */
    public function openingEachShard(): self
    {
        return clone($this, ['opensEachShard' => true]);
    }

    /**
     * This behaviour, where the runner stops each mutant at its first failing
     * test, and so cannot record every killer.
     */
    public function stoppingAtFirstKiller(NotFull $why): self
    {
        return clone($this, ['whyNotFull' => $why]);
    }

    /** This behaviour, where the runner runs this many mutants at once. */
    public function runningAtOnce(Processes $processes): self
    {
        return clone($this, ['parallelism' => $processes]);
    }

    public function holdsAsLoaded(): bool
    {
        return $this->holdsAsLoaded;
    }

    public function raisesLimits(): bool
    {
        return $this->raisesLimits;
    }

    /** The groups whose test files every proof key reads. */
    public function readByEveryKey(): Groups
    {
        return $this->readByEveryKey;
    }

    public function opensEachShard(): bool
    {
        return $this->opensEachShard;
    }

    /**
     * Why a kill matrix of this runner's run holds first killers only: the
     * run did not ask for every killer, or the runner cannot record them.
     */
    public function whyNotFull(): NotFull
    {
        return $this->whyNotFull;
    }

    /** How many mutants the runner runs at once, each in its own process. */
    public function parallelism(): Processes
    {
        return $this->parallelism;
    }
}
