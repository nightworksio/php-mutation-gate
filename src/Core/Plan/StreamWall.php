<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function max;

use NightWorksIO\MutationGate\Core\Time\Seconds;

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

    /** The least second pass's factor a wall scales by, so a measured factor of none prices no work at no time. */
    public const float LEAST_PASS_TWO = 0.05;

    private function __construct(private int $processes, private float $passTwo)
    {
    }

    /** The wall of a job of this many processes, its second pass's children scaled by this factor. */
    public static function of(int $processes, float $passTwo = self::STANDARD_PASS_TWO): self
    {
        return new self(max(1, $processes), max(0.0, $passTwo));
    }

    /** This wall, its second pass's children scaled by this factor, as a job measured it. */
    public function passingTwiceAt(float $passTwo): self
    {
        return self::of($this->processes, $passTwo);
    }

    /** How many seconds of the cost model's a second of this wall holds: its processes', its second pass scaled. */
    public function scale(): float
    {
        return $this->processes / max($this->passTwo, self::LEAST_PASS_TWO);
    }

    /**
     * The wall seconds the plan expects a job to spend on a shard: its cost,
     * that cost spread over these processes where it judges by one part of
     * the test files, as the plan cut it to fit (ADR-0195, ADR-0197).
     */
    public function ofShard(Shard $shard): Seconds
    {
        $cost = $shard->cost()->seconds();

        return Seconds::of($shard->testPart()->isWhole() ? $cost : $cost / $this->scale());
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
