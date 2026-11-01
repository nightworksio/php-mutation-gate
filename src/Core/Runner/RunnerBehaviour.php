<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

/**
 * How a runner behaves where the flows must know it: whether it reads each
 * `#[Holds]` as its test files load, which groups' test files every proof
 * key reads (ADR-0007), whether each shard pays a full opening run under coverage
 * (ADR-0006), whether it can record every test that kills a mutant, for
 * a full kill matrix (ADR-0014), whether it runs a mutant per core, the
 * style its own tests are written in (ADR-0015), and which tests a mutant's
 * own run selects (ADR-0008). The flows ask the runner,
 * never its name.
 */
final readonly class RunnerBehaviour
{
    private function __construct(
        private bool $holdsAsLoaded,
        private Groups $readByEveryKey,
        private bool $opensEachShard,
        private NotFull $whyNotFull,
        private Parallelism $parallelism,
        private AssertionStyle|NotGiven $writes,
        private RunSelection $selection,
    ) {
    }

    /**
     * A runner that lists `#[Holds]` as groups, has no group every key
     * reads, reuses the map the plan handed each shard, runs one mutant at a
     * time, whose tests are PHPUnit classes, and whose mutant's run selects
     * exactly the tests that judge it.
     */
    public static function standard(): self
    {
        return new self(
            holdsAsLoaded: false,
            readByEveryKey: Groups::none(),
            opensEachShard: false,
            whyNotFull: NotFull::FirstKillers,
            parallelism: Parallelism::Serial,
            writes: AssertionStyle::PhpUnit,
            selection: new ExactRun(),
        );
    }

    /**
     * This behaviour, whose runner's own tests are written in this style, as
     * a stub with no test to follow is; or in none a stub is written in.
     */
    public function writingTestsIn(AssertionStyle|NotGiven $style): self
    {
        return new self(
            $this->holdsAsLoaded,
            $this->readByEveryKey,
            $this->opensEachShard,
            $this->whyNotFull,
            $this->parallelism,
            $style,
            $this->selection,
        );
    }

    /**
     * The style the runner's tests are written in, which a stub with no
     * covering test takes (ADR-0015); none where no stub is written in it.
     */
    public function testStyle(): AssertionStyle|NotGiven
    {
        return $this->writes;
    }

    /** This behaviour, reading each `#[Holds]` as the runner loads its test files, where a group may never form. */
    public function holdingAsLoaded(): self
    {
        return new self(
            holdsAsLoaded: true,
            readByEveryKey: $this->readByEveryKey,
            opensEachShard: $this->opensEachShard,
            whyNotFull: $this->whyNotFull,
            parallelism: $this->parallelism,
            writes: $this->writes,
            selection: $this->selection,
        );
    }

    /** This behaviour, where every proof key reads a group's test files, since every shard opens on them. */
    public function readingInEveryKey(Group $group): self
    {
        return new self(
            $this->holdsAsLoaded,
            $this->readByEveryKey->with($group),
            $this->opensEachShard,
            $this->whyNotFull,
            $this->parallelism,
            $this->writes,
            $this->selection,
        );
    }

    /** This behaviour, where each shard pays a full opening run under coverage. */
    public function openingEachShard(): self
    {
        return new self(
            $this->holdsAsLoaded,
            $this->readByEveryKey,
            opensEachShard: true,
            whyNotFull: $this->whyNotFull,
            parallelism: $this->parallelism,
            writes: $this->writes,
            selection: $this->selection,
        );
    }

    /**
     * This behaviour, where the runner stops each mutant at its first failing
     * test, and so cannot record every killer.
     */
    public function stoppingAtFirstKiller(NotFull $why): self
    {
        return new self(
            $this->holdsAsLoaded,
            $this->readByEveryKey,
            $this->opensEachShard,
            $why,
            $this->parallelism,
            $this->writes,
            $this->selection,
        );
    }

    /** This behaviour, where the runner runs one mutant per core, as many at once as a request asks. */
    public function runningPerCore(): self
    {
        return new self(
            $this->holdsAsLoaded,
            $this->readByEveryKey,
            $this->opensEachShard,
            $this->whyNotFull,
            Parallelism::PerCore,
            $this->writes,
            $this->selection,
        );
    }

    /** This behaviour, where a mutant's own run selects the tests that judge it this way. */
    public function selecting(RunSelection $selection): self
    {
        return new self(
            $this->holdsAsLoaded,
            $this->readByEveryKey,
            $this->opensEachShard,
            $this->whyNotFull,
            $this->parallelism,
            $this->writes,
            $selection,
        );
    }

    /** Which tests a mutant's own run runs of those that judge it: what a control that reproduces the run runs. */
    public function selection(): RunSelection
    {
        return $this->selection;
    }

    public function holdsAsLoaded(): bool
    {
        return $this->holdsAsLoaded;
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
