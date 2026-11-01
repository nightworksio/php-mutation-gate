<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitLab;

use NightWorksIO\MutationGate\Core\Report\Markdown;

use function strtr;

/**
 * The sticky comment's markdown as a GitLab note holds it (ADR-0048): the line that links the HTML report links
 * the pipeline, which keeps it among its artifacts, and each character GitLab reads as the start of a reference,
 * `@`, `#`, `!` and `~`, written as an entity where the project wrote it, stands in an element of its own. GitLab
 * finds references in the text of the rendered note, where an entity is already its character, and never across
 * two elements.
 */
final readonly class NoteMarkdown
{
    /** Each reference's first character as the comment's escaping writes it, and the same in an element of its own. */
    private const array REFERENCES = [
        '&#64;' => '<span>&#64;</span>',
        '&#35;' => '<span>&#35;</span>',
        '&#33;' => '<span>&#33;</span>',
        '&#126;' => '<span>&#126;</span>',
    ];

    /** The comment as a note of the pipeline at this URL. */
    public static function of(string $markdown, string $pipeline): string
    {
        return strtr($markdown, [Markdown::REPORT_SLOT => Markdown::runLine($pipeline), ...self::REFERENCES]);
    }
}
