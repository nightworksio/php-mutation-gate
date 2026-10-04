<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;

/**
 * How a runner looks for the tests that kill each mutant: the order it runs
 * the mutant's covering tests in (ADR-0013, decision 3), and whether it stops
 * at the first that fails or runs every one and records each that fails, a
 * full kill matrix (ADR-0014, decision 7). The order decides which test is
 * found first, so it matters only where the run stops there.
 */
final readonly class KillSearch
{
    private function __construct(private Ordering $ordering, private MatrixKind $matrix)
    {
    }

    /** The runner's own order, stopping at the first killer. */
    public static function standard(): self
    {
        return new self(Ordering::runner(), MatrixKind::FirstKiller);
    }

    public static function of(Ordering $ordering, MatrixKind $matrix): self
    {
        return new self($ordering, $matrix);
    }

    public function ordering(): Ordering
    {
        return $this->ordering;
    }

    /** Whether each mutant stops at its first killer, or records every one. */
    public function matrix(): MatrixKind
    {
        return $this->matrix;
    }
}
