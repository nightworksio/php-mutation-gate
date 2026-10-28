<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;

/**
 * The command lines a CI's jobs run against the plan `plan` wrote, each
 * naming the gate as it was started (ADR-0006, ADR-0022): a shard's run,
 * which reads its shard from the CI, one shard's run, and the verdict over
 * every shard's results.
 */
final readonly class JobCommands
{
    private const string SHARD = '%s --shard=%d';

    private function __construct(private string $run, private string $verdict)
    {
    }

    public static function of(string $run, string $verdict): self
    {
        return new self($run, $verdict);
    }

    /** The run of the shard the CI names to the gate. */
    public function run(): string
    {
        return $this->run;
    }

    /** The run of this one shard. */
    public function shard(ShardId $shard): string
    {
        return sprintf(self::SHARD, $this->run, $shard->number());
    }

    /** The verdict over every shard's results. */
    public function verdict(): string
    {
        return $this->verdict;
    }
}
