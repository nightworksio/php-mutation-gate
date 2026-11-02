<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;

/**
 * The command lines a CI's jobs run against the plan `plan` wrote, each
 * naming the gate as it was started (ADR-0006, ADR-0022): a shard's run,
 * which reads its shard from the CI, one shard's run, and the verdict over
 * every shard's results. Where the plan left what needs a credential for
 * `deliver`, a verdict may leave its own too (ADR-0007, ADR-0024).
 */
final readonly class JobCommands
{
    /** The option of `plan`, `survivors` and `verdict` that leaves what needs a credential for `deliver`. */
    public const string DELIVER_LATER = 'deliver-later';

    private const string SHARD = '%s --shard=%d';

    private const string LATER = '%s --%s';

    private function __construct(private string $run, private string $verdict, private bool $deliversLater)
    {
    }

    public static function of(string $run, string $verdict): self
    {
        return new self($run, $verdict, deliversLater: false);
    }

    /** The same, for a plan that left what needs a credential for `deliver`. */
    public function deliveringLater(): self
    {
        return new self($this->run, $this->verdict, deliversLater: true);
    }

    /** Whether the plan left what needs a credential for `deliver`. */
    public function deliversLater(): bool
    {
        return $this->deliversLater;
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

    /** The verdict over every shard's results, leaving what needs a credential for `deliver`. */
    public function verdictDeliveredLater(): string
    {
        return sprintf(self::LATER, $this->verdict, self::DELIVER_LATER);
    }
}
