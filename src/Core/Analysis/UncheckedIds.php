<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

/**
 * The survivors static analysis left unchecked, by why (ADR-0020): which of
 * them a shard waits on rather than counting as a doom is
 * `run.failFastWaits` (ADR-0103).
 *
 * @internal
 */
final readonly class UncheckedIds
{
    /** @param array<string, MutantIds> $ids by the reason's value */
    private function __construct(private array $ids)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, with a survivor left unchecked for a reason. */
    public function with(Unchecked $why, MutantId $survivor): self
    {
        return $this->and($why, MutantIds::of($survivor));
    }

    /** These, with survivors left unchecked for a reason. */
    public function and(Unchecked $why, MutantIds $survivors): self
    {
        $held = array_key_exists($why->value, $this->ids) ? $this->ids[$why->value] : MutantIds::none();

        return new self([...$this->ids, $why->value => $held->and($survivors)]);
    }

    /** These, with those others left unchecked, each for its reason. */
    public function plus(self $others): self
    {
        $ids = $this;

        foreach (Unchecked::cases() as $why) {
            $ids = $ids->and($why, $others->for($why));
        }

        return $ids;
    }

    /** The survivors left unchecked for any of these reasons. */
    public function for(Unchecked ...$reasons): MutantIds
    {
        $ids = MutantIds::none();

        foreach ($reasons as $why) {
            $ids = array_key_exists($why->value, $this->ids) ? $ids->and($this->ids[$why->value]) : $ids;
        }

        return $ids;
    }
}
