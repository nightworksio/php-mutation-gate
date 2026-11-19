<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The run that judged a pass, as its record keeps it (ADR-0198): the digest
 * of every input it read, its address as its CI names it, and whether it
 * carried no unit's result, each judged or proved by a proof whose key
 * matched.
 */
final readonly class JudgingRun
{
    private function __construct(private Digest|NotGiven $inputs, private string $address, private bool $whole)
    {
    }

    /** A run that read inputs of this digest, where it is known, at this address, carrying no unit's result or some. */
    public static function of(Digest|NotGiven $inputs, string $address, bool $whole): self
    {
        return new self($inputs, $address, $whole);
    }

    /** A run its record says nothing of. */
    public static function unrecorded(): self
    {
        return new self(NotGiven::value(), '', whole: false);
    }

    /** This run, having read inputs of this digest. */
    public function reading(Digest $inputs): self
    {
        return new self($inputs, $this->address, $this->whole);
    }

    /** The digest of every input it read; none where its record does not say. */
    public function inputs(): Digest|NotGiven
    {
        return $this->inputs;
    }

    /** Whether it read inputs of this digest. */
    public function readSame(Digest $inputs): bool
    {
        return $this->inputs instanceof Digest && $this->inputs->value() === $inputs->value();
    }

    /** Its address, as its CI names it; empty where its record does not say. */
    public function address(): string
    {
        return $this->address;
    }

    /** Whether it carried no unit's result, so a run of every unit may reuse its verdict. */
    public function isWhole(): bool
    {
        return $this->whole;
    }
}
