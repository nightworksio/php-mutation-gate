<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * What a proof records of the inputs its result came from, beside its key
 * (ADR-0008, decision 1): the digest of the unit's source, of what decides its
 * mutant set besides it, and of each test file that killed one of its
 * mutants, with the support that file reads.
 */
final readonly class Inputs
{
    /** @param ByPath<Digest> $tests each killing test file's digest, with what it reads, by its path */
    private function __construct(private Digest $source, private Digest $mutation, private ByPath $tests)
    {
    }

    public static function of(Digest $source, Digest $mutation): self
    {
        return new self($source, $mutation, ByPath::none());
    }

    /** These inputs, and a killing test file's digest, with what it reads. */
    public function withTest(Path $file, Digest $digest): self
    {
        return new self($this->source, $this->mutation, $this->tests->with($file, $digest));
    }

    /** The digest of the unit's source. */
    public function source(): Digest
    {
        return $this->source;
    }

    /** The digest of what decides its mutant set besides its source. */
    public function mutation(): Digest
    {
        return $this->mutation;
    }

    /** A killing test file's digest, with what it reads; missing where no mutant it killed recorded it. */
    public function testDigest(Path $file): Digest|Missing
    {
        return $this->tests->at($file, Missing::at($file));
    }

    /** @return ByPath<Digest> each killing test file's digest, with what it reads, by its path */
    public function tests(): ByPath
    {
        return $this->tests;
    }
}
