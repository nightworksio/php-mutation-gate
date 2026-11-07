<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function min;

use NightWorksIO\MutationGate\Core\Config\Absent;

/**
 * What a first estimate takes a shard to be made of, before any shard has
 * timed its units (ADR-0114): how many covering tests of average time a killed
 * mutant's run takes, the share of mutants that survive and run every covering
 * test, and how many times its mutants' own runs a shard takes with its
 * controls, its baselines and its static checks.
 */
final readonly class FirstRunShape
{
    /** The covering tests of average time a killed mutant takes, where the config does not say. */
    public const int TESTS_BEFORE_A_KILL = 5;

    /** The share of mutants that survive, where the config does not say. */
    public const float SURVIVING = 0.02;

    /** How many times its mutants' own runs a shard takes, where the config does not say. */
    public const float SHARD_OVERHEAD = 2.5;

    public function __construct(
        public int $testsBeforeAKill = self::TESTS_BEFORE_A_KILL,
        public float $surviving = self::SURVIVING,
        public float $shardOverhead = self::SHARD_OVERHEAD,
    ) {
    }

    /** These, each where the config says it. */
    public static function of(int|Absent $tests, int|float|Absent $surviving, int|float|Absent $overhead): self
    {
        return new self(
            $tests instanceof Absent ? self::TESTS_BEFORE_A_KILL : $tests,
            $surviving instanceof Absent ? self::SURVIVING : $surviving,
            $overhead instanceof Absent ? self::SHARD_OVERHEAD : $overhead,
        );
    }

    /** What one mutant's covering tests are expected to spend: this many, this long in all. */
    public function covering(int $tests, float $seconds): float
    {
        $killed = $tests === 0 ? 0.0 : min($seconds, $this->testsBeforeAKill * $seconds / $tests);

        return (1.0 - $this->surviving) * $killed + $this->surviving * $seconds;
    }
}
