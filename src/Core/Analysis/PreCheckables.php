<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The mutants a run offers to static analysis before their tests, in the
 * order it made them.
 *
 * @implements IteratorAggregate<int, PreCheckable>
 */
final readonly class PreCheckables implements Countable, IteratorAggregate
{
    /** @param list<PreCheckable> $mutants */
    private function __construct(private array $mutants)
    {
    }

    public static function of(PreCheckable ...$mutants): self
    {
        return new self(array_values($mutants));
    }

    public function count(): int
    {
        return count($this->mutants);
    }

    /** @return Traversable<int, PreCheckable> */
    public function getIterator(): Traversable
    {
        yield from $this->mutants;
    }
}
