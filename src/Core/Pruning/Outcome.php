<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

/**
 * What one judged mutant came to, for its mutator's window (ADR-0025,
 * decision 2): killed, in any way, or let through, as a survivor, a flaky,
 * an unjudged or an uncovered mutant is.
 */
final readonly class Outcome
{
    private function __construct(private string $mutator, private string $mutant, private bool $through)
    {
    }

    /** A mutant of this mutator, by the runner's name for it, that was killed. */
    public static function killed(string $mutator, string $mutant): self
    {
        return new self($mutator, $mutant, through: false);
    }

    /** A mutant of this mutator, by the runner's name for it, that let the change through. */
    public static function through(string $mutator, string $mutant): self
    {
        return new self($mutator, $mutant, through: true);
    }

    public function mutator(): string
    {
        return $this->mutator;
    }

    /** The mutant's id. */
    public function mutant(): string
    {
        return $this->mutant;
    }

    public function letThrough(): bool
    {
        return $this->through;
    }
}
