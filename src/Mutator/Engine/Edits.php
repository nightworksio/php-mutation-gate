<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_values;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * A mutator's changes to one piece of code, one per node it changed, in the
 * order the nodes stand.
 *
 * @internal the engine's own
 *
 * @implements IteratorAggregate<int, Edit>
 */
final readonly class Edits implements IteratorAggregate
{
    /** @param list<Edit> $edits */
    private function __construct(private array $edits)
    {
    }

    public static function of(Edit ...$edits): self
    {
        return new self(array_values($edits));
    }

    /** @return Traversable<int, Edit> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->edits);
    }
}
