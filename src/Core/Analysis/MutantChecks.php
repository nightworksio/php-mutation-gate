<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Mutants an analyser checks in one go, in order (ADR-0020, decision 12):
 * side by side where it can run several checks at once.
 *
 * @implements IteratorAggregate<int, MutantCheck>
 */
final readonly class MutantChecks implements Countable, IteratorAggregate
{
    /** @param list<MutantCheck> $checks */
    private function __construct(private array $checks)
    {
    }

    public static function of(MutantCheck ...$checks): self
    {
        return new self(array_values($checks));
    }

    public function count(): int
    {
        return count($this->checks);
    }

    /** @return Traversable<int, MutantCheck> */
    public function getIterator(): Traversable
    {
        yield from $this->checks;
    }
}
