<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How often an analyser rejected one mutator's mutants (ADR-0020, decision
 * 11): of the mutants of it the analyser checked, how many it rejected.
 */
final readonly class RejectionRate
{
    private function __construct(private string $mutator, private int $checks, private int $rejections)
    {
    }

    public static function of(string $mutator, int $checks, int $rejections): self
    {
        return new self($mutator, $checks, $rejections);
    }

    /** The rate of a mutator the analyser has not yet checked a mutant of. */
    public static function unchecked(string $mutator): self
    {
        return new self($mutator, 0, 0);
    }

    public function mutator(): string
    {
        return $this->mutator;
    }

    public function checks(): int
    {
        return $this->checks;
    }

    public function rejections(): int
    {
        return $this->rejections;
    }

    /** This rate, after one more check the mutant passed. */
    public function passed(): self
    {
        return new self($this->mutator, $this->checks + 1, $this->rejections);
    }

    /** This rate, after one more check that rejected the mutant. */
    public function rejected(): self
    {
        return new self($this->mutator, $this->checks + 1, $this->rejections + 1);
    }

    /** This rate and another's of the same mutator, added: every check of both, and every rejection. */
    public function plus(self $other): self
    {
        return new self($this->mutator, $this->checks + $other->checks, $this->rejections + $other->rejections);
    }

    /**
     * The test time a check before the tests saves on average: the tests'
     * time for the share of mutants the analyser rejects, which then never
     * run them. Nothing for a mutator never checked.
     */
    public function saving(Seconds $tests): Seconds
    {
        return Seconds::of($this->checks === 0 ? 0.0 : $tests->seconds() * $this->rejections / $this->checks);
    }
}
