<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

use NightWorksIO\MutationGate\Core\Report\Commented;
use NightWorksIO\MutationGate\Core\Report\MarkdownItems;

/**
 * How many of a list the comment shows, or how many characters of one text, as `comment.<cap>.most` says
 * (ADR-0102); a list 0 caps is left out.
 */
enum Cap: string
{
    case Survivors = 'survivors';
    case Security = 'security';
    case Unjudged = 'unjudged';
    case Ignored = 'ignored';
    case Heatmap = 'heatmap';
    case Text = 'text';

    /** How many entries of a list the comment has always shown at most. */
    private const int LIST = Commented::MOST;

    /** How many files that fell the comment shows at most. */
    private const int FALLEN = 5;

    /** How many characters of one diff or hint the comment has always shown at most. */
    private const int CHARACTERS = MarkdownItems::SHOWN_CHARACTERS;

    /** How many it shows where the config says nothing. */
    public function standard(): int
    {
        return match ($this) {
            self::Survivors, self::Security, self::Unjudged, self::Ignored => self::LIST,
            self::Heatmap => self::FALLEN,
            self::Text => self::CHARACTERS,
        };
    }
}
