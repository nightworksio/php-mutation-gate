<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_values;

use ArrayIterator;

use function count;

use Countable;

use function in_array;

use IteratorAggregate;
use Traversable;

/**
 * Names a manifest lists, in the order it lists them: the packages it
 * requires, or the extension classes it declares.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class Names implements Countable, IteratorAggregate
{
    /** @param list<string> $names */
    private function __construct(private array $names)
    {
    }

    public static function of(string ...$names): self
    {
        return new self(array_values($names));
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names, strict: true);
    }

    public function count(): int
    {
        return count($this->names);
    }

    /** @return Traversable<int, string> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->names);
    }
}
