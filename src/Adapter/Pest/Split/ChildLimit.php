<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function max;
use function round;

/**
 * How long a split stream's child may run in its test (ADR-0117):
 * `timeouts.perTest` times the parent's own time of the same test, never
 * under `timeouts.testFloor`; or `timeouts.most` where `timeouts.perTest` is
 * off, or while the parent's own time of the test is not yet known and the
 * coverage map has none. Where the stream counts steps (ADR-0147), a child
 * is held to `timeouts.perTest` times the parent's steps from the fork to the
 * test's end, plus `outbreak.stepFloor`, and its time to `timeouts.most`
 * alone.
 *
 * @internal
 */
final readonly class ChildLimit
{
    /**
     * @param float $multiple  the times its own a test may take, or 0 where `timeouts.perTest` is off
     * @param float $floor     the least seconds a test may take
     * @param float $most      the most seconds a test may take
     * @param int   $stepFloor the least steps a child may take past its parent's share, or 0 where none are counted
     */
    public function __construct(
        private float $multiple,
        private float $floor,
        private float $most,
        private int $stepFloor = 0,
    ) {
    }

    /** The seconds a child may run in a test the parent took, or the map timed, this long; the most where neither. */
    public function of(float|false $own): float
    {
        return $own === false || $this->multiple <= 0.0
            ? $this->most
            : max($this->floor, $own * $this->multiple);
    }

    /** The most seconds a child may run in a test, whatever its parent took. */
    public function longest(): float
    {
        return $this->most;
    }

    /** Whether a child is held to its parent's steps, where the stream counts them. */
    public function countsSteps(): bool
    {
        return $this->stepFloor > 0 && $this->multiple > 0.0;
    }

    /** The most steps a child forked at this count may reach, once its parent ended the test at that one. */
    public function stepsOf(int $atFork, int $atEnd): int
    {
        return $atFork + (int) round($this->multiple * ($atEnd - $atFork)) + $this->stepFloor;
    }
}
