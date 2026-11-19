<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `reach.defaultBranchSince`: what the default branch's changes are read since (ADR-0141). */
enum DefaultBranchSince: string
{
    /** Its last judged run, for every unit, and its last pass for the units that run left open; on every run. */
    case Judged = 'judged';

    /** As `judged` off the default branch; a push to it reads its change since its last pass. */
    case JudgedOffBranch = 'judged-off-branch';

    /** Its last pass, for every unit and every run. */
    case Passed = 'passed';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Judged => 'Reach::sinceJudged()',
            self::JudgedOffBranch => 'Reach::sinceJudgedOffBranch()',
            self::Passed => 'Reach::sincePassed()',
        };
    }

    /** Whether a run on the default branch, or one off it, reads since the last judged run. */
    public function readsJudged(bool $onTheDefaultBranch): bool
    {
        return $this === self::Judged || ($this === self::JudgedOffBranch && ! $onTheDefaultBranch);
    }
}
