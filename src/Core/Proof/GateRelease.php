<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function explode;

use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\ThisPackage;

/**
 * The release of the gate that measured something, as its version spells it:
 * a release by its tag, a branch by its name and commit. What one release
 * spends on a unit says nothing of another's.
 */
final readonly class GateRelease
{
    /** What separates a branch build's version from its commit, as `Version::spelt()` writes them. */
    private const string BETWEEN = ' ';

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

    /**
     * Whether what the other release measured is what this one spends: the
     * same release, or two builds of a branch, whatever the branch and the
     * commit, which run the code under development and learn from each
     * other's runs (ADR-0031).
     */
    public function costsAs(self $other): bool
    {
        return $this->equals($other) || $this->isBranch() && $other->isBranch();
    }

    public function value(): string
    {
        return $this->spelt;
    }

    /** Whether a build of a branch measured it: its version, the first word it spells, names no release. */
    private function isBranch(): bool
    {
        return $this->isRecorded()
            && ! Version::of(ThisPackage::COMPOSER, explode(self::BETWEEN, $this->spelt)[0], '')->isRelease();
    }
}
