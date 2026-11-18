<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function max;

/**
 * The wall seconds a job's split streams take over some test files and
 * mutants (ADR-0195): the parents run each test file once, a mutant whose
 * likely killer the ledger names forks at that test, and every other forks
 * at each covering test, as a second pass does, its children's seconds
 * scaled by the second pass's factor; all of it shared by the job's
 * processes, but no less than its longest test file, which one stream runs.
 *
 * @internal
 */
final readonly class StreamWall
{
    /** The second pass's factor where no run has measured one: each child runs its whole test. */
    public const float STANDARD_PASS_TWO = 1.0;

    private function __construct(private int $processes, private float $passTwo)
    {
    }

    /** The wall of a job of this many processes, its second pass's children scaled by this factor. */
    public static function of(int $processes, float $passTwo = self::STANDARD_PASS_TWO): self
    {
        return new self(max(1, $processes), max(0.0, $passTwo));
    }

    public function processes(): int
    {
        return $this->processes;
    }

    public function passTwo(): float
    {
        return $this->passTwo;
    }

    /**
     * The wall seconds of a first pass: the parents' test files, of which
     * this one is the longest, and the forks at the ledger's killers.
     */
    public function first(float $files, float $longest, float $killers): float
    {
        return max(($files + $killers) / $this->processes, $longest);
    }

    /** The wall seconds of a second pass whose mutants' covering tests take these seconds together. */
    public function second(float $covering): float
    {
        return $covering * $this->passTwo / $this->processes;
    }
}
