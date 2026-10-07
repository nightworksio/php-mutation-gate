<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function max;
use function min;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a mutant's limit is kept between (ADR-0008, decision 2): never less
 * than the floor, `timeouts.seconds`, and never more than the most,
 * `timeouts.most`.
 */
final readonly class LimitBounds
{
    private function __construct(private Seconds $floor, private Seconds $most)
    {
    }

    public static function between(Seconds $floor, Seconds $most): self
    {
        return new self($floor, $most);
    }

    /** No floor, and this most: a runner's own rule that only caps, as Infection's does. */
    public static function upTo(Seconds $most): self
    {
        return new self(Seconds::of(0.0), $most);
    }

    /** `timeouts.seconds`: the least any mutant is allowed, and what one whose tests are not timed is. */
    public function floor(): Seconds
    {
        return $this->floor;
    }

    /** `timeouts.most`: the most any mutant is allowed. */
    public function most(): Seconds
    {
        return $this->most;
    }

    /** These bounds with another most, as a run again asks for. */
    public function upToInstead(Seconds $most): self
    {
        return new self($this->floor, $most);
    }

    /** These seconds, raised to the floor and lowered to the most. */
    public function kept(Seconds $seconds): Seconds
    {
        return Seconds::of(min(max($this->floor->seconds(), $seconds->seconds()), $this->most->seconds()));
    }
}
