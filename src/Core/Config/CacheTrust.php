<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * Which packs of `outbreak cache` the default branch and a merge group read
 * (ADR-0131): `outbreak.cache.trust`.
 */
enum CacheTrust: string
{
    /** Only the packs the default branch's own runs wrote. */
    case DefaultBranch = 'default-branch';

    /** Those, and the packs any branch of the repository wrote. */
    case Repository = 'repository';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::DefaultBranch => 'OutbreakCache::trustingTheDefaultBranch()',
            self::Repository => 'OutbreakCache::trustingTheRepository()',
        };
    }

    /** Whether it loosens what the default branch judges (ADR-0142). */
    public function isLoose(): bool
    {
        return $this === self::Repository;
    }
}
