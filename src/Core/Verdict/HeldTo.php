<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Which floors a verdict holds a run to (ADR-0003, ADR-0010, ADR-0021): the
 * trees' and the security sets', as a run off a pull request is held; those
 * and the new code's, as a pull request and `pre-push` are; the new code's
 * alone, as a `watch` round is, which shows each tree against its floor
 * without holding it; or the security sets' alone, as `--security` is.
 */
enum HeldTo
{
    case Trees;
    case TreesAndNewCode;
    case NewCode;

    /** The security sets alone, as `--security` holds a run of the security mutators (ADR-0021, decision 20). */
    case Security;

    /**
     * The floors a command names, or, where it names none, those its run
     * decides: a pull request's trees and new code, and any other run's trees.
     */
    public static function named(self|NotGiven $named, bool $pullRequest): self
    {
        return match (true) {
            $named instanceof self => $named,
            $pullRequest => self::TreesAndNewCode,
            default => self::Trees,
        };
    }

    /** Whether a tree below its floor fails the verdict. */
    public function holdsTrees(): bool
    {
        return $this === self::Trees || $this === self::TreesAndNewCode;
    }

    /** Whether the verdict judges the new code against its floor. */
    public function holdsNewCode(): bool
    {
        return $this === self::TreesAndNewCode || $this === self::NewCode;
    }

    /** Whether a security set below its floor fails the verdict. */
    public function holdsSecurity(): bool
    {
        return $this !== self::NewCode;
    }
}
