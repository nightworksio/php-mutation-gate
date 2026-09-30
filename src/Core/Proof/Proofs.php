<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_any;
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
        $collected = [];

        foreach ($proofs as $proof) {
            $collected += [$proof->key()->value() => $proof];
        }

        return new self($collected);
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

    /**
     * Whether any of these proofs was established at this base. A key can
     * only match a proof of the same base, so where none was, no key of a
     * run at it can.
     */
    public function provesAt(Digest $base): bool
    {
        return array_any($this->proofs, fn(Proof $proof): bool => $proof->run()->base()->value() === $base->value());
    }

    /** The newest proof of each unit's path, whatever its key, read in one pass. */
    public function newest(): NewestProofs
    {
        return NewestProofs::in($this);
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
