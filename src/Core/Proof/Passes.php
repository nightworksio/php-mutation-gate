<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Passes that record the inputs their runs read, the newest first, which a
 * later run may reuse (ADR-0198).
 *
 * @implements IteratorAggregate<int, Passed>
 */
final readonly class Passes implements Countable, IteratorAggregate
{
    /** @param list<Passed> $passes */
    private function __construct(private array $passes)
    {
    }

    public static function of(Passed ...$passes): self
    {
        return new self(array_values($passes));
    }

    public function count(): int
    {
        return count($this->passes);
    }

    /** @return Traversable<int, Passed> */
    public function getIterator(): Traversable
    {
        yield from $this->passes;
    }
}
