<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function intdiv;
use function max;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\Comment\Cap;
use NightWorksIO\MutationGate\Core\Comment\Section;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The sticky comment as the config shapes it (ADR-0102): its sections in the order `comment.sections` lists them,
 * the verdict first, each open or folded as `comment.open` says, each list cut to its `comment.<cap>.most` and left
 * out where that is 0; within what GitHub takes, showing half as many of each list's entries each time until it
 * fits, none at the last.
 *
 * @internal
 */
final readonly class CommentDocument
{
    /** Where a folded block opens, under what it holds. */
    public const string FOLDED = '<details><summary>%s</summary>';

    /** Where a folded block ends. */
    public const string FOLD_END = '</details>';
    /** The lists the comment cuts to fit, and so halves. */
    private const array LISTS = [Cap::Survivors, Cap::Security, Cap::Unjudged, Cap::Ignored];

    public static function of(Verdict $verdict, CommentShows $shows): string
    {
        $parts = CommentParts::of($verdict, $shows);
        $largest = max(array_map($shows->most(...), self::LISTS));
        $document = static function (int $count) use ($parts, $shows, $largest): string {
            $blocks = [Markdown::MARKER];
            $shown = static fn(Cap $cap): int => intdiv($shows->most($cap) * $count, $largest);

            foreach ($shows->sections() as $section) {
                $blocks = self::isLeftOut($section, $shows)
                    ? $blocks
                    : [...$blocks, ...self::rendered($section, $parts->section($section, $shown), $shows)];
            }

            return MarkdownBlocks::document($blocks);
        };

        return MarkdownBlocks::fitted(
            $document,
            $largest,
            static fn(string $comment): bool => mb_strlen($comment) <= Markdown::COMMENT_CHARACTERS,
        );
    }

    /** Whether the section is a list its `comment.<cap>.most` sets to 0. */
    private static function isLeftOut(Section $section, CommentShows $shows): bool
    {
        $cap = Cap::tryFrom($section->value);

        return $cap instanceof Cap && $shows->most($cap) === 0;
    }

    /**
     * A section's parts, each under its heading where it is open, or folded under it, or under the section's
     * title where the part has no heading; nothing of a part with no blocks. The verdict is always open.
     *
     * @param  list<array{string, list<string>}> $parts
     * @return list<string>
     */
    private static function rendered(Section $section, array $parts, CommentShows $shows): array
    {
        $open = $section === Section::Verdict || $shows->isOpen($section);
        $rendered = [];

        foreach ($parts as [$heading, $blocks]) {
            $title = $heading === '' ? $section->title() : $heading;
            $rendered = [...$rendered, ...self::part($title, $heading, $blocks, $open)];
        }

        return $rendered;
    }

    /**
     * One part: its blocks under its heading, or as they are where it has none, where it is open; folded under its
     * heading, or its section's title, where it is not; nothing where it has no blocks.
     *
     * @param  list<string> $blocks
     * @return list<string>
     */
    private static function part(string $title, string $heading, array $blocks, bool $open): array
    {
        $said = array_values(array_filter($blocks, static fn(string $block): bool => $block !== ''));

        return match (true) {
            $said === [] => [],
            ! $open => [self::folded($title, $said)],
            $heading === '' => $said,
            default => MarkdownBlocks::section($heading, $said),
        };
    }

    /**
     * Blocks folded under what they hold.
     *
     * @param list<string> $blocks
     */
    private static function folded(string $heading, array $blocks): string
    {
        return implode("\n\n", [sprintf(self::FOLDED, Escape::text($heading)), ...$blocks, self::FOLD_END]);
    }
}
