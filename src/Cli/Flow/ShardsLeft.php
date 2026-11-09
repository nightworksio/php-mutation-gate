<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;

/**
 * What a job of a run whose shards pull their work left (ADR-0134): each
 * item's result it wrote, and why it ran its own items alone, where its
 * claims failed.
 *
 * @internal
 */
final readonly class ShardsLeft
{
    private const string NONE = 'Shard %d ran no item: the other shards took every one.';

    private const string ALONE = 'Shard %d runs its own items alone: %s';

    /**
     * @param list<ShardLeft> $each
     * @param list<string>    $notes
     */
    private function __construct(private ShardId $job, private array $each, private array $notes)
    {
    }

    public static function none(ShardId $job): self
    {
        return new self($job, [], []);
    }

    /** These, with one more item's result. */
    public function with(ShardLeft $left): self
    {
        return new self($this->job, [...$this->each, $left], $this->notes);
    }

    /** These, saying once why the job runs its own items alone. */
    public function alone(CannotJudge $why): self
    {
        return $this->notes === []
            ? new self($this->job, $this->each, [sprintf(self::ALONE, $this->job->number(), $why->why())])
            : $this;
    }

    /** Where each result was written, and why the job ran its own items alone, where it did. */
    public function said(): string
    {
        $written = array_map(static fn(ShardLeft $left): string => $left->said(), $this->each);

        return implode("\n", [
            ...$this->notes,
            ...$written === [] ? [sprintf(self::NONE, $this->job->number())] : $written,
        ]);
    }

    /** Why the job fails, where an item's result leaves the run no way to pass; nothing where it still can. */
    public function doomed(): string
    {
        foreach ($this->each as $left) {
            if ($left->doomed() !== '') {
                return $left->doomed();
            }
        }

        return '';
    }
}
