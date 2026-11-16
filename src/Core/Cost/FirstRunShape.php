<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function count;
use function min;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

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

    private function __construct(private int $testsBeforeAKill, private float $surviving, private float $shardOverhead)
    {
    }

    /** Each part as the config does not say it. */
    public static function standard(): self
    {
        return new self(self::TESTS_BEFORE_A_KILL, self::SURVIVING, self::SHARD_OVERHEAD);
    }

    /** These, each where the config says it, and as standard where it does not. */
    public static function of(
        int|Absent $tests = new Absent(),
        int|float|Absent $surviving = new Absent(),
        int|float|Absent $overhead = new Absent(),
    ): self {
        return new self(
            $tests instanceof Absent ? self::TESTS_BEFORE_A_KILL : $tests,
            $surviving instanceof Absent ? self::SURVIVING : $surviving,
            $overhead instanceof Absent ? self::SHARD_OVERHEAD : $overhead,
        );
    }

    /** What one mutant's covering tests are expected to spend, these tests taking this long in all. */
    public function covering(TestIds $tests, Seconds $every): Seconds
    {
        $all = $every->seconds();
        $killed = count($tests) === 0 ? 0.0 : min($all, $this->testsBeforeAKill * $all / count($tests));

        return Seconds::of((1.0 - $this->surviving) * $killed + $this->surviving * $all);
    }

    /** What a shard is expected to take, its mutants' own runs taking this long. */
    public function shard(Seconds $own): Seconds
    {
        return Seconds::of($own->seconds() * $this->shardOverhead);
    }
}
