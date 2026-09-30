<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

/**
 * Why a static analyser killed a mutant (ADR-0020, decision 10): the
 * analyser that rejected it, and the error it found that the original does
 * not have. A mutant's record keeps it, in a shard's results and in a proof,
 * so `explain` can say what rejected the mutant.
 */
final readonly class Rejection
{
    private function __construct(private string $analyser, private Finding $finding)
    {
    }

    public static function by(string $analyser, Finding $finding): self
    {
        return new self($analyser, $finding);
    }

    /** The analyser's name, as its identity gives it. */
    public function analyser(): string
    {
        return $this->analyser;
    }

    public function finding(): Finding
    {
        return $this->finding;
    }
}
