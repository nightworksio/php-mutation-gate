<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_intersect;
use function array_values;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Mutators by the names the gate gives their mutants: a registered one's
 * `<set>/<Name>`, and a runner's own as that runner names it.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class NamedMutators implements IteratorAggregate
{
    /** @param list<string> $names */
    private function __construct(private array $names)
    {
    }

    public static function of(string ...$names): self
    {
        return new self(array_values($names));
    }

    /** Whether these and the others share a name. */
    public function meet(self $others): bool
    {
        return array_intersect($this->names, $others->names) !== [];
    }

    /** @return Traversable<int, string> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->names);
    }
}
