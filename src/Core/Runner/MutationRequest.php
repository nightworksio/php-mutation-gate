<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Coverage\Fresh;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * What a runner is asked to mutate: some files, judged by the whole suite, a
 * group or a filter. By default it leaves nothing out, applies every mutator,
 * has no deadline, runs one process, collects its own coverage, runs each
 * mutant's tests in the runner's own order and caps no process's memory. It never says how uncovered
 * mutants score: a runner reports every one, and the gate applies `uncovered`
 * when it judges (ADR-0003, ADR-0004).
 */
final readonly class MutationRequest
{
    private function __construct(
        private Paths $files,
        private WholeSuite|Group|Filter $judgedBy,
        private Paths $leftOut,
        private Mutators $mutators,
        private Seconds|Unlimited $deadline,
        private Processes $processes,
        private Path|Fresh $coverage,
        private Withheld $withheld,
        private Ordering $ordering,
        private MemoryCap $memory,
    ) {
    }

    public static function of(Paths $files, WholeSuite|Group|Filter $judgedBy): self
    {
        return new self(
            $files,
            $judgedBy,
            Paths::none(),
            Mutators::all(),
            Unlimited::time(),
            Processes::single(),
            Fresh::coverage(),
            Withheld::standard(),
            Ordering::runner(),
            MemoryCap::none(),
        );
    }

    /** This request, leaving out paths inside its files that a group judges in a run of its own. */
    public function leavingOut(Paths $paths): self
    {
        return clone($this, ['leftOut' => $paths]);
    }

    /**
     * This request over these files alone, with only these mutators, and
     * nothing left out: how a request asks for some mutators only, and how a
     * run again makes some of its mutants once more, judged, covered,
     * withheld, capped, timed and ordered as this request is.
     */
    public function narrowedTo(Paths $files, Mutators $mutators): self
    {
        return clone($this, ['files' => $files, 'mutators' => $mutators, 'leftOut' => Paths::none()]);
    }

    /** This request, stopped when this much time has passed. */
    public function within(Seconds $deadline): self
    {
        return clone($this, ['deadline' => $deadline]);
    }

    public function across(Processes $processes): self
    {
        return clone($this, ['processes' => $processes]);
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

    /** This request, reading the coverage map left in a directory instead of running the suite for one. */
    public function reusingCoverage(Path $directory): self
    {
        return clone($this, ['coverage' => $directory]);
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

    /** This request, running each mutant's covering tests in this order where the runner can. */
    public function orderedBy(Ordering $ordering): self
    {
        return clone($this, ['ordering' => $ordering]);
    }

    public function ordering(): Ordering
    {
        return $this->ordering;
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

    public function mutators(): Mutators
    {
        return $this->mutators;
    }

    public function deadline(): Seconds|Unlimited
    {
        return $this->deadline;
    }

    public function processes(): Processes
    {
        return $this->processes;
    }

    public function coverage(): Path|Fresh
    {
        return $this->coverage;
    }
}
