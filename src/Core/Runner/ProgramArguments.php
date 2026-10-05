<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_values;

use IteratorAggregate;
use Traversable;

/**
 * A program to run, then its arguments, in order.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class ProgramArguments implements IteratorAggregate
{
    /** @param list<string> $arguments */
    private function __construct(private array $arguments)
    {
    }

    public static function of(string ...$arguments): self
    {
        return new self(array_values($arguments));
    }

    /** @return Traversable<int, string> */
    public function getIterator(): Traversable
    {
        yield from $this->arguments;
    }
}
