<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function count;
use function hash;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

use Traversable;

/**
 * When each part of a run began and how long it took, from the flows' clock
 * and the shards' result files, and what the whole run took: the one set of
 * numbers the console, the JSON report, the cost and OpenTelemetry all read
 * (ADR-0016, decisions 18 and 19).
 */
final readonly class RunTimings
{
    /** How many hex characters a trace id has: 16 bytes. */
    private const int TRACE = 32;

    /**
     * @param list<ShardTiming> $shards
     */
    private function __construct(
        private string $run,
        private Phase|Unmeasured $plan,
        private array $shards,
        private Phase|Unmeasured $verdict,
        private RunTime $spent,
    ) {
    }

    /** The timings of a run, as a proof names it (ADR-0007), that took this long. */
    public static function of(string $run, RunTime $spent): self
    {
        return new self($run, Unmeasured::duration(), [], Unmeasured::duration(), $spent);
    }

    public function withPlan(Phase $plan): self
    {
        return clone($this, ['plan' => $plan]);
    }

    public function withShard(ShardTiming $shard): self
    {
        return clone($this, ['shards' => [...$this->shards, $shard]]);
    }

    public function withVerdict(Phase $verdict): self
    {
        return clone($this, ['verdict' => $verdict]);
    }

    public function run(): string
    {
        return $this->run;
    }

    /**
     * The trace every job of the run adds its spans to, with nothing passed
     * between them: the first 16 bytes of `sha256("mutation-gate:" + run)`,
     * in lowercase hex (ADR-0016, decision 15).
     */
    public function traceId(): string
    {
        return mb_substr(hash('sha256', sprintf('mutation-gate:%s', $this->run)), 0, self::TRACE);
    }

    public function plan(): Phase|Unmeasured
    {
        return $this->plan;
    }

    /** @return Traversable<int, ShardTiming> */
    public function shards(): Traversable
    {
        yield from $this->shards;
    }

    public function verdict(): Phase|Unmeasured
    {
        return $this->verdict;
    }

    /** What the whole run took. */
    public function spent(): RunTime
    {
        return $this->spent;
    }

    /** Whether the run was cut into more than one shard. */
    public function isSharded(): bool
    {
        return count($this->shards) > 1;
    }
}
