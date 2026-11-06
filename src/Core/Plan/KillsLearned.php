<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Multiple;

/**
 * What a plan learned from the ledgers' kills for every shard: the
 * `timeouts.multiple` each mutant is allowed, where the config has it
 * learned (ADR-0073), and the pace of those kills, which a check before a
 * mutant's tests is weighed by, where `staticCheck.testsTime` is learned
 * (ADR-0077).
 *
 * @internal
 */
final readonly class KillsLearned
{
    private function __construct(private Multiple|NotGiven $multiple, private Multiple|NotGiven $pace)
    {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), NotGiven::value());
    }

    /** These, with this `timeouts.multiple` learned. */
    public function allowing(Multiple $multiple): self
    {
        return new self($multiple, $this->pace);
    }

    /** These, with kills learned to take this pace. */
    public function pacing(Multiple $pace): self
    {
        return new self($this->multiple, $pace);
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
