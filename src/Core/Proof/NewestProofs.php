<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The newest proof of each unit's path, by when its run ended, whatever its
 * key, the first of two as new: read once from a ledger's proofs, and then
 * answered for any path at once.
 */
final readonly class NewestProofs
{
    /** @param array<string, Proof> $newest by the unit's path */
    private function __construct(private array $newest)
    {
    }

    public static function in(Proofs $proofs): self
    {
        $newest = [];

        foreach ($proofs as $proof) {
            $unit = $proof->unit()->value();
            $newer = ! array_key_exists($unit, $newest) || $proof->run()->at()->isAfter($newest[$unit]->run()->at());
            $newest[$unit] = $newer ? $proof : $newest[$unit];
        }

        return new self($newest);
    }

    /** The newest proof of a unit's path; never proved where there is none. */
    public function of(Path $unit): Proof|NeverProved
    {
        return array_key_exists($unit->value(), $this->newest)
            ? $this->newest[$unit->value()]
            : NeverProved::unit($unit);
    }
}
