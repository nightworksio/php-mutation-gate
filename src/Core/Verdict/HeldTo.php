<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Which floors a verdict holds a run to (ADR-0003, ADR-0010): the trees', as
 * a run off a pull request is held; the trees' and the new code's, as a pull
 * request and `pre-push` are; or the new code's alone, as a `watch` round
 * is, which shows each tree against its floor without holding it.
 */
enum HeldTo
{
    case Trees;
    case TreesAndNewCode;
    case NewCode;

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
        return $this !== self::NewCode;
    }

    /** Whether the verdict judges the new code against its floor. */
    public function holdsNewCode(): bool
    {
        return $this !== self::Trees;
    }
}
