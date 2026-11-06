<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Digest;

/**
 * What a ledger's proofs point into as it is written: each mutator's index
 * among its mutator names, each test's among its tests, each base's among its
 * bases, and the digests its proofs share.
 *
 * @internal the shape of the ledger file
 */
final readonly class ProofsShared
{
    /**
     * @param array<string, int> $mutators each mutator's index, by its name
     * @param array<string, int> $tests    each test's index, by its id
     * @param array<string, int> $bases    each base's index, by its digest
     */
    private function __construct(
        private array $mutators,
        private array $tests,
        private InputsTable $inputs,
        private array $bases,
    ) {
    }

    /**
     * @param array<string, int> $mutators each mutator's index, by its name
     * @param array<string, int> $tests    each test's index, by its id
     * @param array<string, int> $bases    each base's index, by its digest
     */
    public static function of(array $mutators, array $tests, InputsTable $inputs, array $bases): self
    {
        return new self($mutators, $tests, $inputs, $bases);
    }

    /** @return array<string, int> each mutator's index, by its name */
    public function mutators(): array
    {
        return $this->mutators;
    }

    /** @return array<string, int> each test's index, by its id */
    public function tests(): array
    {
        return $this->tests;
    }

    public function inputs(): InputsTable
    {
        return $this->inputs;
    }

    /** A base as a proof points to it: its index among the ledger's bases, or its digest where they do not hold it. */
    public function base(Digest $base): int|string
    {
        return array_key_exists($base->value(), $this->bases) ? $this->bases[$base->value()] : $base->value();
    }
}
