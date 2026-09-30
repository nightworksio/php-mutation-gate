<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Files with the digest of each, one per path; a later fingerprint of a path
 * replaces the earlier.
 *
 * @implements IteratorAggregate<int, Fingerprint>
 */
final readonly class Fingerprints implements Countable, IteratorAggregate
{
    /** @param array<string, Fingerprint> $fingerprints by path */
    private function __construct(private array $fingerprints)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Fingerprint ...$fingerprints): self
    {
        $collected = [];

        foreach ($fingerprints as $fingerprint) {
            $collected[$fingerprint->path()->value()] = $fingerprint;
        }

        return new self($collected);
    }

    public function with(Fingerprint $fingerprint): self
    {
        $fingerprints = $this->fingerprints;
        $fingerprints[$fingerprint->path()->value()] = $fingerprint;

        return new self($fingerprints);
    }

    public function digestOf(Path $path): Digest|Missing
    {
        return array_key_exists($path->value(), $this->fingerprints)
            ? $this->fingerprints[$path->value()]->digest()
            : Missing::at($path);
    }

    public function count(): int
    {
        return count($this->fingerprints);
    }

    /** @return Traversable<int, Fingerprint> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->fingerprints));
    }
}
