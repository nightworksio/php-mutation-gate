<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a plan tells the verdict of what its run reads: the digest of every
 * input, where the plan read them (ADR-0198), and whether its coverage map
 * was measured against the map its own scope keeps (ADR-0023).
 */
final readonly class InputsRead
{
    private function __construct(private Digest|NotGiven $digest, private bool $ownScopeCoverage)
    {
    }

    /** Nothing read of the inputs, and a map measured against no map of its own scope. */
    public static function none(): self
    {
        return new self(NotGiven::value(), ownScopeCoverage: false);
    }

    /** These, every input having this digest. */
    public function digested(Digest $digest): self
    {
        return new self($digest, $this->ownScopeCoverage);
    }

    /** These, the coverage map measured against the map its own scope keeps. */
    public function onOwnScopeCoverage(): self
    {
        return new self($this->digest, ownScopeCoverage: true);
    }

    /** The digest of every input; none where the plan did not read them. */
    public function digest(): Digest|NotGiven
    {
        return $this->digest;
    }

    public function isOnOwnScopeCoverage(): bool
    {
        return $this->ownScopeCoverage;
    }
}
