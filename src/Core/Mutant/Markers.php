<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A runner's own ignore markers, in the order they were found.
 *
 * @implements IteratorAggregate<int, Marker>
 */
final readonly class Markers implements Countable, IteratorAggregate
{
    /** @param list<Marker> $markers */
    private function __construct(private array $markers)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Marker ...$markers): self
    {
        return new self(array_values($markers));
    }

    public function with(Marker $marker): self
    {
        return new self([...$this->markers, $marker]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->markers, ...$other->markers]);
    }

    public function count(): int
    {
        return count($this->markers);
    }

    /** @return Traversable<int, Marker> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->markers);
    }
}
