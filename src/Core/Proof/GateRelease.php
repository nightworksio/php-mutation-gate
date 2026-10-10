<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Runner\Version;

/**
 * The release of the gate that measured something, as its version spells it:
 * a release by its tag, a branch by its name and commit. What one release
 * spends on a unit says nothing of another's.
 */
final readonly class GateRelease
{
    private function __construct(private string $spelt)
    {
    }

    /** The release a ledger or result spells, as it spells it. */
    public static function spelt(string $spelt): self
    {
        return new self($spelt);
    }

    /** No release: what a ledger written before releases were recorded holds. */
    public static function unrecorded(): self
    {
        return new self('');
    }

    /** The release this version of the gate is. */
    public static function of(Version $gate): self
    {
        return new self($gate->spelt());
    }

    public function isRecorded(): bool
    {
        return $this->spelt !== '';
    }

    public function equals(self $other): bool
    {
        return $this->spelt === $other->spelt;
    }

    public function value(): string
    {
        return $this->spelt;
    }
}
