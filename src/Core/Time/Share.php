<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

use function max;

/** A share of a time: the whole of it, or a part above none. */
final readonly class Share
{
    private function __construct(private float $share)
    {
    }

    public static function whole(): self
    {
        return new self(1.0);
    }

    public static function of(float $share): self
    {
        return new self($share);
    }

    /**
     * What is left of a time once this share of another is kept back from
     * it, never less than none: all of it where the share is the whole,
     * which keeps nothing back, and where either has no limit.
     */
    public function leaving(Seconds|Unlimited $left, Seconds|Unlimited $of): Seconds|Unlimited
    {
        return $left instanceof Seconds && $of instanceof Seconds && $this->share < 1.0
            ? Seconds::of(max(0.0, $left->seconds() - $of->seconds() * $this->share))
            : $left;
    }

    /** This share of a time; a time with no limit stays without one. */
    public function taken(Seconds|Unlimited $time): Seconds|Unlimited
    {
        return $time instanceof Seconds ? Seconds::of($time->seconds() * $this->share) : $time;
    }
}
