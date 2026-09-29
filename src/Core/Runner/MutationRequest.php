<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Coverage\Fresh;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * What a runner is asked to mutate: some files, judged by the whole suite, a
 * group or a filter. By default it leaves nothing out, applies every mutator,
 * has no deadline, counts uncovered mutants, runs one process and collects its
 * own coverage.
 */
final readonly class MutationRequest
{
    private function __construct(
        private Paths $files,
        private WholeSuite|Group|Filter $judgedBy,
        private Paths $leftOut,
        private Mutators $mutators,
        private Seconds|Unlimited $deadline,
        private Uncovered $uncovered,
        private Processes $processes,
        private Path|Fresh $coverage,
    ) {}

    public static function of(Paths $files, WholeSuite|Group|Filter $judgedBy): self
    {
        return new self($files, $judgedBy, Paths::none(), Mutators::all(), Unlimited::time(), Uncovered::Count, Processes::of(1), Fresh::coverage());
    }

    /** This request, leaving out paths inside its files that a group judges in a run of its own. */
    public function leavingOut(Paths $paths): self
    {
        return clone($this, ['leftOut' => $paths]);
    }

    public function onlyMutators(Mutators $mutators): self
    {
        return clone($this, ['mutators' => $mutators]);
    }

    /** This request, stopped when this much time has passed. */
    public function within(Seconds $deadline): self
    {
        return clone($this, ['deadline' => $deadline]);
    }

    public function treatingUncovered(Uncovered $uncovered): self
    {
        return clone($this, ['uncovered' => $uncovered]);
    }

    public function across(Processes $processes): self
    {
        return clone($this, ['processes' => $processes]);
    }

    /** This request, reading the coverage map left in a directory instead of running the suite for one. */
    public function reusingCoverage(Path $directory): self
    {
        return clone($this, ['coverage' => $directory]);
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

    public function uncovered(): Uncovered
    {
        return $this->uncovered;
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
