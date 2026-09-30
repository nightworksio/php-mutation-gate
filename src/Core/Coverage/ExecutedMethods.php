<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The methods of a file some test ran, in the order the report lists them.
 *
 * @implements IteratorAggregate<int, ExecutedMethod>
 */
final readonly class ExecutedMethods implements Countable, IteratorAggregate
{
    /** @param list<ExecutedMethod> $methods */
    private function __construct(private array $methods)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(ExecutedMethod ...$methods): self
    {
        return new self(array_values($methods));
    }

    public function count(): int
    {
        return count($this->methods);
    }

    /** @return Traversable<int, ExecutedMethod> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->methods);
    }
}
