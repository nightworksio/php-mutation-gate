<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * How many of a mutant's covering tests a shard judged it by, where a run
 * cuts its test files across its shards (ADR-0117): every one, or those of
 * the shard's part alone, the other parts judging the rest. The shard whose
 * part holds the mutant's first covering test owns it, and alone checks or
 * proves its survival by anything but its tests.
 */
enum TestShare
{
    /** Every covering test: the result is the mutant's whole answer. */
    case All;

    /** The covering tests of the shard's part, which holds the first of them. */
    case OwnedPart;

    /** The covering tests of the shard's part, another part holding the first of them. */
    case OtherPart;

    /**
     * The share a shard noted: its part's covering tests, its part owning
     * the mutant or another part owning it; every one where it noted neither.
     */
    public static function of(bool $owned, bool $otherOwned): self
    {
        return match (true) {
            $owned => self::OwnedPart,
            $otherOwned => self::OtherPart,
            default => self::All,
        };
    }

    /** Whether a survivor so judged survived every covering test, so it alone can doom the run (ADR-0008). */
    public function isWhole(): bool
    {
        return $this === self::All;
    }

    /**
     * Whether the shard checks a survivor so judged by anything but its
     * tests, such as static analysis: unless another part owns it.
     */
    public function isOwned(): bool
    {
        return $this !== self::OtherPart;
    }
}
