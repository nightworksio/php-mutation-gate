<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function max;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How long one test of a mutant's run may run before the run is stopped
 * (ADR-0090): a multiple of the test's own time, `timeouts.perTest`, never
 * under a floor, `timeouts.testFloor`; or no such limit, where the run is
 * held to its silence limit and its whole limit alone.
 */
final readonly class PerTestLimit
{
    /** How many times its own time a test may run, by default. */
    public const int MULTIPLE = 3;

    /** The least a test may run for, in seconds, by default. */
    public const int FLOOR = 3;

    private function __construct(private int $multiple, private Seconds $floor)
    {
    }

    /** The standard limit: three times a test's own time, never under three seconds. */
    public static function standard(): self
    {
        return new self(self::MULTIPLE, Seconds::of(self::FLOOR));
    }

    /** This multiple of a test's own time, never under this floor; a multiple of none is no limit. */
    public static function of(int $multiple, Seconds $floor): self
    {
        return new self(max(0, $multiple), $floor);
    }

    /** No limit on one test. */
    public static function off(): self
    {
        return new self(0, Seconds::of(self::FLOOR));
    }

    public function isOn(): bool
    {
        return $this->multiple > 0;
    }

    /** How many times its own time a test may run; none where it is off. */
    public function multiple(): int
    {
        return $this->multiple;
    }

    public function floor(): Seconds
    {
        return $this->floor;
    }

    /** How long a test of this own time may run. */
    public function allowing(Seconds $own): Seconds
    {
        return Seconds::of(max($this->floor->seconds(), $own->seconds() * $this->multiple));
    }
}
