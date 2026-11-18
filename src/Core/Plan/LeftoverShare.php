<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

/**
 * One share of the mutants a shard left at its deadline, which one job of
 * the second wave judges (ADR-0190): the shard, the share's number among
 * that shard's shares, counted from 1, and the mutants it lists.
 *
 * @internal
 */
final readonly class LeftoverShare
{
    /** @param list<LeftMutant> $mutants */
    private function __construct(private ShardId $shard, private int $number, private array $mutants)
    {
    }

    public static function of(ShardId $shard, int $number, LeftMutant ...$mutants): self
    {
        return new self($shard, $number, array_values($mutants));
    }

    public function shard(): ShardId
    {
        return $this->shard;
    }

    public function number(): int
    {
        return $this->number;
    }

    /** @return list<LeftMutant> */
    public function listed(): array
    {
        return $this->mutants;
    }

    public function mutants(): MutantIds
    {
        return MutantIds::of(...array_map(static fn(LeftMutant $mutant): MutantId => $mutant->id(), $this->mutants));
    }

    /** @return list<string> the runner's own ids of its mutants */
    public function natives(): array
    {
        return array_map(static fn(LeftMutant $mutant): string => $mutant->native(), $this->mutants);
    }

    /** The files its mutants mutate. */
    public function files(): Paths
    {
        return Paths::of(...array_map(static fn(LeftMutant $mutant): Path => $mutant->file(), $this->mutants));
    }
}
