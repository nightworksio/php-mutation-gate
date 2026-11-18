<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function max;

/**
 * The wall seconds a job's split streams take over some test files and
 * mutants (ADR-0195, ADR-0196): the parents run each test file once, a
 * mutant whose likely killer the ledger names forks at that test, and a
 * second pass forks each mutant the first left at each covering test, its
 * children's seconds, every mutant's covering tests' together, scaled by
 * the second pass's factor, which recent jobs of the second wave measured;
 * all of it shared by the job's processes, but no less than its longest
 * test file, which one stream runs.
 *
 * @internal
 */
final readonly class StreamWall
{
    /** The second pass's factor where no run has measured one: every mutant is forked at each covering test. */
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
