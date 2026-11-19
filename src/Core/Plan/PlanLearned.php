<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Cost\JobsLearned;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Multiple;

/**
 * What a plan learned from the ledgers for every shard: of their kills, the
 * `timeouts.multiple` each mutant is allowed, where the config has it
 * learned (ADR-0073), and the pace of those kills, which a check before a
 * mutant's tests is weighed by, where `staticCheck.testsTime` is learned
 * (ADR-0077); and of their jobs, what jobs on its platform take (ADR-0196).
 */
final readonly class PlanLearned
{
    private function __construct(
        private Multiple|NotGiven $multiple,
        private Multiple|NotGiven $pace,
        private JobsLearned $jobs,
    ) {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), JobsLearned::none());
    }

    /** These, with this `timeouts.multiple` learned. */
    public function allowing(Multiple $multiple): self
    {
        return new self($multiple, $this->pace, $this->jobs);
    }

    /** These, with kills learned to take this pace. */
    public function pacing(Multiple $pace): self
    {
        return new self($this->multiple, $pace, $this->jobs);
    }

    /** These, with what jobs on the plan's platform were learned to take. */
    public function timing(JobsLearned $jobs): self
    {
        return new self($this->multiple, $this->pace, $jobs);
    }

    /** What jobs on the plan's platform were learned to take: their setup, wind-down and second pass's factor. */
    public function jobs(): JobsLearned
    {
        return $this->jobs;
    }

    /** The learned `timeouts.multiple`; none where none was learned. */
    public function multiple(): Multiple|NotGiven
    {
        return $this->multiple;
    }

    /** The median of how many times its covering tests' own time each kill took; none where none was learned. */
    public function pace(): Multiple|NotGiven
    {
        return $this->pace;
    }
}
