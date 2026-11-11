<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Config\CacheTrust;

/**
 * Which class of writer a pack of `outbreak cache` holds the proofs of
 * (ADR-0131): each class its own pack.
 *
 * @internal
 */
enum CacheClass: string
{
    /** A push, schedule or dispatch on the default branch, with the default branch's credential or cache scope. */
    case Trusted = 'trusted';

    /** Any other run of the repository that holds a write credential: a branch's push, or its own pull request. */
    case Branch = 'branch';

    /** The class a run writes as: the default branch's runs trusted, every other run branch. */
    public static function writtenBy(bool $onTheDefaultBranch): self
    {
        return $onTheDefaultBranch ? self::Trusted : self::Branch;
    }

    /**
     * The classes a run reads, the first first: every run reads trusted packs;
     * a pull request and a branch's push read branch packs too; the default
     * branch and a merge group read them only under `outbreak.cache.trust:
     * repository`.
     *
     * @return list<self>
     */
    public static function readBy(bool $defaultBranchOrGroup, CacheTrust $trust): array
    {
        return ! $defaultBranchOrGroup || $trust === CacheTrust::Repository
            ? [self::Trusted, self::Branch]
            : [self::Trusted];
    }

    /** Whether a proof of this class counts as one of the run's own scope (ADR-0081). */
    public function isOwnScope(): bool
    {
        return $this === self::Branch;
    }
}
