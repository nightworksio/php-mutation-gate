<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;

/**
 * A survivor offered to static analysis after its tests (ADR-0020), as its analyser reads it.
 *
 * @internal
 */
final readonly class CheckableSurvivor implements CheckableMutant
{
    private function __construct(private Mutant $mutant, private Checkable $checkable)
    {
    }

    public static function of(Mutant $mutant, Checkable $checkable): self
    {
        return new self($mutant, $checkable);
    }

    public function mutant(): Mutant
    {
        return $this->mutant;
    }

    public function checkable(): Checkable
    {
        return $this->checkable;
    }
}
