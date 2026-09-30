<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use function array_any;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * What a mutator is about, each once.
 *
 * @implements IteratorAggregate<int, Tag>
 */
final readonly class Tags implements Countable, IteratorAggregate
{
    /** @param array<string, Tag> $tags by name */
    private function __construct(private array $tags)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Tag ...$tags): self
    {
        $each = [];

        foreach ($tags as $tag) {
            $each[$tag->name()] = $tag;
        }

        return new self($each);
    }

    public function has(Tag $tag): bool
    {
        return array_any($this->tags, $tag->equals(...));
    }

    public function count(): int
    {
        return count($this->tags);
    }

    /** @return Traversable<int, Tag> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->tags));
    }
}
