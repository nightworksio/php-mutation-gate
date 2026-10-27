<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Owner;

use NightWorksIO\MutationGate\Core\Score\Floor;

/** An owner CODEOWNERS names, and the floor `owners.floors` holds the code of its files to (ADR-0022). */
final readonly class OwnerFloor
{
    private function __construct(private Owner $owner, private Floor $floor)
    {
    }

    public static function of(Owner $owner, Floor $floor): self
    {
        return new self($owner, $floor);
    }

    public function owner(): Owner
    {
        return $this->owner;
    }

    public function floor(): Floor
    {
        return $this->floor;
    }
}
