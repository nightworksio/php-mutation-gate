<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

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

    /** This share of a time; a time with no limit stays without one. */
    public function taken(Seconds|Unlimited $time): Seconds|Unlimited
    {
        return $time instanceof Seconds ? Seconds::of($time->seconds() * $this->share) : $time;
    }
}
