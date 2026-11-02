<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Owner\OwnerGroup;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

/**
 * One part of a list of survivors: the owner group it is under, or none
 * where the run read no CODEOWNERS, how many survivors that is, the items
 * shown of it and how many items it has (ADR-0022).
 *
 * @internal
 */
final readonly class OwnedPart
{
    /** @param list<JudgedMutant|Cluster> $shown */
    public function __construct(
        private OwnerGroup|NotGiven $group,
        private int $survivors,
        private array $shown,
        private int $items,
    ) {
    }

    public function group(): OwnerGroup|NotGiven
    {
        return $this->group;
    }

    public function survivors(): int
    {
        return $this->survivors;
    }

    /** @return list<JudgedMutant|Cluster> */
    public function shown(): array
    {
        return $this->shown;
    }

    /** How many items the part has, shown or not. */
    public function items(): int
    {
        return $this->items;
    }
}
