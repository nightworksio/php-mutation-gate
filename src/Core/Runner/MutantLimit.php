<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function min;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The seconds a runner allows one mutant's run where it times each mutant
 * by its tests (ADR-0008, decision 2): PHPUnit's start-up, plus a multiple
 * of the covering tests' own time, and never more than the cap,
 * `timeouts.seconds`. Where the tests' time is not measured, the cap.
 */
final readonly class MutantLimit
{
    /** What the standard limit allows PHPUnit to start in, as Infection does. */
    private const float START_UP = 5.0;

    /** How many times its covering tests' own time the standard limit allows a mutant, as Infection does. */
    private const int FACTOR = 5;

    private function __construct(private Seconds $startUp, private int $factor)
    {
    }

    /** 5 s, plus five times the covering tests' own time: Infection's own limit, which the PHPUnit runner shares. */
    public static function standard(): self
    {
        return new self(Seconds::of(self::START_UP), self::FACTOR);
    }

    /** The limit of a mutant whose covering tests take this long, under this cap. */
    public function of(Seconds|Unmeasured $tests, Seconds $cap): Seconds
    {
        return $tests instanceof Seconds
            ? Seconds::of(min($this->startUp->seconds() + $this->factor * $tests->seconds(), $cap->seconds()))
            : $cap;
    }
}
