<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function max;
use function min;

/** How many times its covering tests' own time a mutant's run took, or is allowed. */
final readonly class Multiple
{
    private function __construct(private float $times)
    {
    }

    public static function of(float $times): self
    {
        return new self($times);
    }

    /** Three times: what the standard limit allows where `timeouts.multiple` is not written. */
    public static function standard(): self
    {
        return new self(MutantLimit::FACTOR);
    }

    public function times(): float
    {
        return $this->times;
    }

    public function isStandard(): bool
    {
        return $this->times === (float) MutantLimit::FACTOR;
    }

    /** This multiple, raised to the least and lowered to the most. */
    public function within(self $least, self $most): self
    {
        return new self(min(max($this->times, $least->times), $most->times));
    }
}
