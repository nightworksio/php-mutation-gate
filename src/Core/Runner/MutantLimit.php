<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The seconds a runner allows one mutant's run where it times each mutant
 * by its tests (ADR-0008, decision 2): a start-up, plus a multiple of the
 * covering tests' own time, kept between the bounds. Where the tests' time
 * is not measured, the floor.
 */
final readonly class MutantLimit
{
    /** What the standard limit allows a run to start in. */
    private const float START_UP = 5.0;

    /** How many times its covering tests' own time the standard limit allows, above a busy runner's slowdown. */
    private const int FACTOR = 3;

    /** How many times its covering tests' own time Infection's own limit allows a mutant. */
    private const int INFECTION_FACTOR = 5;

    private function __construct(private Seconds $startUp, private int $factor)
    {
    }

    /** 5 s, plus three times the covering tests' own time: the limit the gate lays. */
    public static function standard(): self
    {
        return new self(Seconds::of(self::START_UP), self::FACTOR);
    }

    /** 5 s, plus five times the covering tests' own time: the limit Infection lays itself. */
    public static function infections(): self
    {
        return new self(Seconds::of(self::START_UP), self::INFECTION_FACTOR);
    }

    /** The limit of a mutant whose covering tests take this long, kept between these bounds. */
    public function of(Seconds|Unmeasured $tests, LimitBounds $bounds): Seconds
    {
        return $tests instanceof Seconds
            ? $bounds->kept(Seconds::of($this->startUp->seconds() + $this->factor * $tests->seconds()))
            : $bounds->floor();
    }
}
