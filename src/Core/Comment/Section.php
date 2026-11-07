<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

/**
 * A section of the pull request comment, as `comment.sections` names it and orders it (ADR-0102): the verdict's
 * heading, which is always first, then whichever of the rest a config lists.
 */
enum Section: string
{
    case Verdict = 'verdict';
    case Security = 'security';
    case Trees = 'trees';
    case Sets = 'sets';
    case Heatmap = 'heatmap';
    case Survivors = 'survivors';
    case Unjudged = 'unjudged';
    case Ignored = 'ignored';
    case Problems = 'problems';
    case Notes = 'notes';
    case Floors = 'floors';
    case Report = 'report';
    case Costs = 'costs';

    /** What a folded section says it holds, where the section has no heading of its own. */
    public function title(): string
    {
        return match ($this) {
            self::Verdict => 'Verdict',
            self::Security => 'Security survivors',
            self::Trees => 'Trees',
            self::Sets => 'Sets',
            self::Heatmap => 'Files that fell',
            self::Survivors => 'Survivors',
            self::Unjudged => 'Unjudged and flaky',
            self::Ignored => 'Ignored',
            self::Problems => 'Problems',
            self::Notes => 'Notes',
            self::Floors => 'Floors that can rise',
            self::Report => 'Report',
            self::Costs => 'What the run cost',
        };
    }
}
