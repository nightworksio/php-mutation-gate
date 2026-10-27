<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Owner;

use function array_values;

use ArrayIterator;
use IteratorAggregate;

use function ksort;

use Traversable;

/**
 * The floor of each owner `owners.floors` holds to one, in the order of the
 * owners' names, one to an owner (ADR-0022).
 *
 * @implements IteratorAggregate<int, OwnerFloor>
 */
final readonly class OwnerFloors implements IteratorAggregate
{
    /** @param list<OwnerFloor> $floors */
    private function __construct(private array $floors)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These floors, one to an owner, the last written for it standing. */
    public static function of(OwnerFloor ...$floors): self
    {
        $byOwner = [];

        foreach ($floors as $floor) {
            $byOwner[$floor->owner()->value()] = $floor;
        }

        ksort($byOwner, SORT_STRING);

        return new self(array_values($byOwner));
    }

    /** @return Traversable<int, OwnerFloor> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->floors);
    }
}
