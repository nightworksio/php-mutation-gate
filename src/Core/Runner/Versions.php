<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Installed versions, one per package; a later version of a package replaces
 * the earlier.
 *
 * @implements IteratorAggregate<int, Version>
 */
final readonly class Versions implements Countable, IteratorAggregate
{
    /** @param array<string, Version> $versions by package */
    private function __construct(private array $versions)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Version ...$versions): self
    {
        $collected = [];

        foreach ($versions as $version) {
            $collected[$version->package()] = $version;
        }

        return new self($collected);
    }

    public function with(Version $version): self
    {
        $versions = $this->versions;
        $versions[$version->package()] = $version;

        return new self($versions);
    }

    public function count(): int
    {
        return count($this->versions);
    }

    /** @return Traversable<int, Version> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->versions));
    }
}
