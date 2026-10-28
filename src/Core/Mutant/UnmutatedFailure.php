<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * Tests that fail without any mutant, as the unmutated control that judges
 * a mutant found them, and why that leaves the mutant without a result
 * (ADR-0008): nothing tells it from its original, so it is left out of every
 * score, and the run cannot be judged.
 */
final readonly class UnmutatedFailure
{
    private function __construct(private TestIds $failing, private Reason $why)
    {
    }

    public static function of(TestIds $failing, Reason $why): self
    {
        return new self($failing, $why);
    }

    /** The tests that failed with the file unmutated. */
    public function failing(): TestIds
    {
        return $this->failing;
    }

    /** Why the mutant it judges has no result. */
    public function why(): Reason
    {
        return $this->why;
    }
}
