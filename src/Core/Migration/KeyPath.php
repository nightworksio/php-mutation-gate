<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function array_slice;
use function count;
use function explode;
use function implode;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\NotGiven;
use Traversable;

/**
 * Where a key is written in a config, from its top, each key under the one
 * before it: `reach.everything`.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class KeyPath implements IteratorAggregate
{
    private const string BETWEEN = '.';

    /** @param non-empty-list<string> $keys */
    private function __construct(private array $keys)
    {
    }

    /** The path a config's keys spell, joined by dots: `reach.everything`. */
    public static function of(string $path): self
    {
        return new self(explode(self::BETWEEN, $path));
    }

    /** The path as the config's keys spell it. */
    public function value(): string
    {
        return implode(self::BETWEEN, $this->keys);
    }

    /** The key it ends in. */
    public function key(): string
    {
        return $this->keys[count($this->keys) - 1];
    }

    /** The path of the object that holds it; none for a key at the top. */
    public function parent(): self|NotGiven
    {
        $parent = array_slice($this->keys, 0, -1);

        return $parent === [] ? NotGiven::value() : new self($parent);
    }

    /** Whether it stands under the same parent as another, both at the top among them. */
    public function isBeside(self $other): bool
    {
        return $this->parentOf($this) === $this->parentOf($other);
    }

    /** @return Traversable<int, string> */
    public function getIterator(): Traversable
    {
        yield from $this->keys;
    }

    private function parentOf(self $path): string
    {
        $parent = $path->parent();

        return $parent instanceof self ? $parent->value() : '';
    }
}
