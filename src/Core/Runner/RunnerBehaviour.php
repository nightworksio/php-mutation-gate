<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

/**
 * How a runner behaves where the flows must know it: whether it reads each
 * `#[Holds]` as its test files load, whether a timeout's limit can be raised
 * for a retry (ADR-0008), which groups' test files every proof key reads
 * (ADR-0007), whether each shard pays a full opening run under coverage
 * (ADR-0006), whether it can record every test that kills a mutant, for
 * a full kill matrix (ADR-0014), whether it runs a mutant per core, and the
 * style its own tests are written in (ADR-0015). The flows ask the runner,
 * never its name.
 */
final readonly class RunnerBehaviour
{
    private function __construct(
        private bool $holdsAsLoaded,
        private bool $raisesLimits,
        private Groups $readByEveryKey,
        private bool $opensEachShard,
        private NotFull $whyNotFull,
        private Parallelism $parallelism,
        private AssertionStyle $writes,
    ) {
    }

    /**
     * A runner that lists `#[Holds]` as groups, can raise a limit, has no
     * group every key reads, reuses the map the plan handed each shard, runs
     * one mutant at a time, and whose tests are PHPUnit classes.
     */
    public static function standard(): self
    {
        return new self(
            holdsAsLoaded: false,
            raisesLimits: true,
            readByEveryKey: Groups::none(),
            opensEachShard: false,
            whyNotFull: NotFull::FirstKillers,
            parallelism: Parallelism::Serial,
            writes: AssertionStyle::PhpUnit,
        );
    }

    /** This behaviour, whose runner's own tests are written in this style, as a stub with no test to follow is. */
    public function writingTestsIn(AssertionStyle $style): self
    {
        return clone($this, ['writes' => $style]);
    }

    /** The style the runner's tests are written in, which a stub with no covering test takes (ADR-0015, decision 2). */
    public function testStyle(): AssertionStyle
    {
        return $this->writes;
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

    /** This behaviour, where the runner runs one mutant per core, as many at once as a request asks. */
    public function runningPerCore(): self
    {
        return clone($this, ['parallelism' => Parallelism::PerCore]);
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

    /**
     * Whether the runner can record this much of the kill matrix: first
     * killers always, and every killer unless it is Infection.
     */
    public function records(MatrixKind $matrix): bool
    {
        return $matrix === MatrixKind::FirstKiller || $this->whyNotFull !== NotFull::Infection;
    }

    /** How the runner runs mutants side by side. */
    public function parallelism(): Parallelism
    {
        return $this->parallelism;
    }
}
