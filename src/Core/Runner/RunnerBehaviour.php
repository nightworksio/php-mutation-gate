<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

/**
 * How a runner behaves where the flows must know it: whether it reads each
 * `#[Holds]` as its test files load, whether a timeout's limit can be raised
 * for a retry (ADR-0008), which groups' test files every proof key reads
 * (ADR-0007), and whether each shard pays a full opening run under coverage
 * (ADR-0006). The flows ask the runner, never its name.
 */
final readonly class RunnerBehaviour
{
    private function __construct(
        private bool $holdsAsLoaded,
        private bool $raisesLimits,
        private Groups $readByEveryKey,
        private bool $opensEachShard,
    ) {
    }

    /**
     * A runner that lists `#[Holds]` as groups, can raise a limit, has no
     * group every key reads, and reuses the map the plan handed each shard.
     */
    public static function standard(): self
    {
        return new self(
            holdsAsLoaded: false,
            raisesLimits: true,
            readByEveryKey: Groups::none(),
            opensEachShard: false,
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
}
