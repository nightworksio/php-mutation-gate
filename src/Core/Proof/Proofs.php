<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Digest;
use Traversable;

/**
 * Proofs, one per key. When two results prove the same key, the first is kept.
 *
 * @implements IteratorAggregate<int, Proof>
 */
final readonly class Proofs implements Countable, IteratorAggregate
{
    /** @param array<string, Proof> $proofs by key */
    private function __construct(private array $proofs)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Proof ...$proofs): self
    {
        $collected = self::none();

        foreach ($proofs as $proof) {
            $collected = $collected->with($proof);
        }

        return $collected;
    }

    public function with(Proof $proof): self
    {
        if ($this->has($proof->key())) {
            return $this;
        }

        $proofs = $this->proofs;
        $proofs[$proof->key()->value()] = $proof;

        return new self($proofs);
    }

    /** These proofs without the one under a key, such as one a fresh result disagrees with. */
    public function without(Digest $key): self
    {
        $proofs = $this->proofs;
        unset($proofs[$key->value()]);

        return new self($proofs);
    }

    public function has(Digest $key): bool
    {
        return array_key_exists($key->value(), $this->proofs);
    }

    public function proofFor(Digest $key): Proof|Unproved
    {
        return $this->has($key) ? $this->proofs[$key->value()] : Unproved::key($key);
    }

    public function count(): int
    {
        return count($this->proofs);
    }

    /** @return Traversable<int, Proof> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->proofs));
    }
}
