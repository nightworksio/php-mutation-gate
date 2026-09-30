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
 * The kills a ledger proved of one unit, in the order it keeps them.
 *
 * @implements IteratorAggregate<int, ProvedKill>
 */
final readonly class ProvedKills implements Countable, IteratorAggregate
{
    /** @param list<ProvedKill> $kills */
    private function __construct(private array $kills)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(ProvedKill ...$kills): self
    {
        return new self(array_values($kills));
    }

    public function count(): int
    {
        return count($this->kills);
    }

    /** @return Traversable<int, ProvedKill> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->kills);
    }
}
