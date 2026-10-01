<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** How long an analyser's checks took (ADR-0020, decision 11): how many it made, and their seconds together. */
final readonly class CheckTime
{
    private function __construct(private int $checks, private Seconds $seconds)
    {
    }

    public static function of(int $checks, Seconds $seconds): self
    {
        return new self($checks, $seconds);
    }

    public static function none(): self
    {
        return new self(0, Seconds::of(0.0));
    }

    public function checks(): int
    {
        return $this->checks;
    }

    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    /** This time, with one more check that took these seconds. */
    public function with(Seconds $took): self
    {
        return new self($this->checks + 1, Seconds::of($this->seconds->seconds() + $took->seconds()));
    }

    /** This time and another's, added: every check of both, and their seconds together. */
    public function plus(self $other): self
    {
        return new self(
            $this->checks + $other->checks,
            Seconds::of($this->seconds->seconds() + $other->seconds->seconds()),
        );
    }

    /** The time of one check, on average; unmeasured before the first. */
    public function each(): Seconds|Unmeasured
    {
        return $this->checks === 0 ? Unmeasured::duration() : Seconds::of($this->seconds->seconds() / $this->checks);
    }
}
