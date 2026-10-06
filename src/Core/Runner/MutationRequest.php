<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Coverage\Fresh;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * What a runner is asked to mutate: some files, judged by the whole suite, a
 * group or a filter. By default it leaves nothing out, applies every
 * mutator, runs every suite's tests, has no deadline, runs one process,
 * collects its own coverage, runs each mutant's tests in the runner's own
 * order, stopping at the first killer, caps no process's memory, and runs
 * each mutant in a fresh process. It
 * never says how uncovered mutants score: a runner reports every one, and
 * the gate applies `uncovered` when it judges (ADR-0003, ADR-0004).
 */
final readonly class MutationRequest
{
    private function __construct(
        private Paths $files,
        private WholeSuite|Group|Filter $judgedBy,
        private Paths $leftOut,
        private Narrowing $narrowing,
        private Seconds|Unlimited $deadline,
        private Pool $pool,
        private Handed|Fresh $coverage,
        private Withheld $withheld,
        private KillSearch $search,
        private MemoryCap $memory,
    ) {
    }

    public static function of(Paths $files, WholeSuite|Group|Filter $judgedBy): self
    {
        return new self(
            $files,
            $judgedBy,
            Paths::none(),
            Narrowing::none(),
            Unlimited::time(),
            Pool::single(),
            Fresh::coverage(),
            Withheld::standard(),
            KillSearch::standard(),
            MemoryCap::none(),
        );
    }

    /** This request, leaving out paths inside its files that a group judges in a run of its own. */
    public function leavingOut(Paths $paths): self
    {
        return clone($this, ['leftOut' => $paths]);
    }

    /**
     * This request over these files alone, narrowed so, and nothing left out:
     * how a request asks for some mutators only, or one suite's tests only
     * (ADR-0025, decision 9), and how a run again makes some of its mutants
     * once more, keeping its narrowing but for the mutators, judged, covered,
     * withheld, capped, timed and ordered as this request is.
     */
    public function narrowedTo(Paths $files, Narrowing $narrowing): self
    {
        return clone($this, ['files' => $files, 'narrowing' => $narrowing, 'leftOut' => Paths::none()]);
    }

    /** This request, stopped when this much time has passed. */
    public function within(Seconds $deadline): self
    {
        return clone($this, ['deadline' => $deadline]);
    }

    /** This request, its mutants run in this pool (ADR-0023). */
    public function across(Pool $pool): self
    {
        return clone($this, ['pool' => $pool]);
    }

    /** This request, withholding these variables from the tests as well as those it already withholds. */
    public function withholding(Withheld $withheld): self
    {
        return clone($this, ['withheld' => $this->withheld->and($withheld)]);
    }

    /** The variables the tests never see. */
    public function withheld(): Withheld
    {
        return $this->withheld;
    }

    /** This request, reading the coverage maps a plan handed over instead of running the suite for one. */
    public function reusingCoverage(Handed $maps): self
    {
        return clone($this, ['coverage' => $maps]);
    }

    /** This request, each process that runs a mutant using no more memory than this (`runner.memory`). */
    public function cappedAt(MemoryCap $memory): self
    {
        return clone($this, ['memory' => $memory]);
    }

    public function memory(): MemoryCap
    {
        return $this->memory;
    }

    /**
     * This request, looking for each mutant's killers this way: its covering
     * tests in this order where the runner can, stopping at the first that
     * fails or recording every one.
     */
    public function searching(KillSearch $search): self
    {
        return clone($this, ['search' => $search]);
    }

    public function search(): KillSearch
    {
        return $this->search;
    }

    public function files(): Paths
    {
        return $this->files;
    }

    public function judgedBy(): WholeSuite|Group|Filter
    {
        return $this->judgedBy;
    }

    public function leftOut(): Paths
    {
        return $this->leftOut;
    }

    /** What the request is narrowed to: the mutators it applies, and the suite whose tests alone judge them. */
    public function narrowing(): Narrowing
    {
        return $this->narrowing;
    }

    public function deadline(): Seconds|Unlimited
    {
        return $this->deadline;
    }

    /** How many mutants run at once, and how each starts. */
    public function pool(): Pool
    {
        return $this->pool;
    }

    public function coverage(): Handed|Fresh
    {
        return $this->coverage;
    }
}
