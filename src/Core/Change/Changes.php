<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Paths;
use Traversable;

/**
 * Every path a change touched, in the order version control listed them.
 *
 * @implements IteratorAggregate<int, Change>
 */
final readonly class Changes implements Countable, IteratorAggregate
{
    /** @param list<Change> $changes */
    private function __construct(private array $changes)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Change ...$changes): self
    {
        return new self(array_values($changes));
    }

    public function with(Change $change): self
    {
        return new self([...$this->changes, $change]);
    }

    public function count(): int
    {
        return count($this->changes);
    }

    /** Every path these changes name, by its name now and before. */
    public function paths(): Paths
    {
        $paths = Paths::none();

        foreach ($this->changes as $change) {
            $paths = $paths->with($change->path())->with($change->previousPath());
        }

        return $paths;
    }

    /** @return Traversable<int, Change> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->changes);
    }
}
