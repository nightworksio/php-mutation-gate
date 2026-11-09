<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function max;

/**
 * How long a split stream's child may run in its test (ADR-0117):
 * `timeouts.perTest` times the parent's own time of the same test, never
 * under `timeouts.testFloor`; or `timeouts.most` where `timeouts.perTest` is
 * off, or while the parent's own time of the test is not yet known and the
 * coverage map has none.
 *
 * @internal
 */
final readonly class ChildLimit
{
    /**
     * @param float $multiple the times its own a test may take, or 0 where `timeouts.perTest` is off
     * @param float $floor    the least seconds a test may take
     * @param float $most     the most seconds a test may take
     */
    public function __construct(private float $multiple, private float $floor, private float $most)
    {
    }

    /** The seconds a child may run in a test the parent took, or the map timed, this long; the most where neither. */
    public function of(float|false $own): float
    {
        return $own === false || $this->multiple <= 0.0
            ? $this->most
            : max($this->floor, $own * $this->multiple);
    }
}
