<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The seconds a runner allows one mutant's run where it times each mutant
 * by its tests (ADR-0008): a start-up, plus a multiple of the
 * covering tests' own time, kept between the bounds. The gate's start-up is
 * `timeouts.startUpMultiple` times how long a run of no test took to start on this machine,
 * where the run measured one, and 5 s where it did not, and its multiple is
 * the bounds', `timeouts.multiple`; Infection's own is always 5 s and five
 * times. Where the tests' time is not measured, the floor.
 *
 * @internal
 */
final readonly class MutantLimit
{
    /** What a limit allows a run to start in where none was measured, or `timeouts.startUp`, and Infection's. */
    public const float START_UP = 5.0;

    /**
     * How many times a run of no test's start-up the standard limit allows,
     * and how many times its covering tests' own time where
     * `timeouts.multiple` is not written, above a busy runner's slowdown.
     */
    public const int FACTOR = 3;

    /** How many times its covering tests' own time Infection's own limit allows a mutant. */
    private const int INFECTION_FACTOR = 5;

    private function __construct(private int $factor, private bool $isTheGates)
    {
    }

    /**
     * Three times the start-up the bounds measured, or 5 s, plus the bounds'
     * multiple of the covering tests' own time: the limit the gate lays.
     */
    public static function standard(): self
    {
        return new self(self::FACTOR, isTheGates: true);
    }

    /** 5 s, plus five times the covering tests' own time: the limit Infection lays itself. */
    public static function infections(): self
    {
        return new self(self::INFECTION_FACTOR, isTheGates: false);
    }

    /** The limit of a mutant whose covering tests take this long, kept between these bounds. */
    public function of(Seconds|Unmeasured $tests, LimitBounds $bounds): Seconds
    {
        return $tests instanceof Seconds
            ? $bounds->kept(
                Seconds::of($this->startUpWithin($bounds) + $this->multipleWithin($bounds) * $tests->seconds()),
            )
            : $bounds->floor();
    }

    /** How many times its covering tests' own time a run is allowed within these bounds: theirs, or Infection's own. */
    private function multipleWithin(LimitBounds $bounds): float
    {
        return $this->isTheGates ? $bounds->multiple()->times() : $this->factor;
    }

    /** What a run is allowed to start in, in seconds, within these bounds. */
    private function startUpWithin(LimitBounds $bounds): float
    {
        $measured = $bounds->startUp();

        return match (true) {
            ! $this->isTheGates => self::START_UP,
            $measured instanceof Seconds => $bounds->startUpMultiple() * $measured->seconds(),
            default => $bounds->unmeasuredStartUp()->seconds(),
        };
    }
}
