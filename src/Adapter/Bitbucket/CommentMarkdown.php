<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Bitbucket;

use function array_map;
use function html_entity_decode;
use function max;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\Report\Markdown;

use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function str_repeat;
use function strtr;

/**
 * The sticky comment's markdown as a Bitbucket Cloud comment holds it (ADR-0048). Bitbucket shows raw HTML as
 * text, so the hidden marker is a link reference definition, which shows nothing; a folded block is its summary in
 * bold, with everything under it shown; and inline code is a code span, its text as the escaping showed it,
 * fenced by more backticks than any run inside it. Every other entity stays one, which Bitbucket shows as its
 * character and reads as no mention. The line that links the HTML report links the pipeline, which keeps it
 * among its artifacts.
 */
final readonly class CommentMarkdown
{
    /** The marker the sticky comment is found by, which Bitbucket shows as nothing. */
    public const string MARKER = '[//]: # (mutation-gate)';

    private const string FOLD = '#<details><summary>(.*?)</summary>#s';

    private const string FOLDED = '**$1**';

    private const string FOLD_END = '</details>';

    private const string CODE = '#<code>(.*?)</code>#s';

    private const string BACKTICKS = '/`+/';

    /** The comment as Bitbucket holds it, linking the pipeline at this URL. */
    public static function of(string $markdown, string $pipeline): string
    {
        $marked = strtr($markdown, [
            Markdown::MARKER => self::MARKER,
            Markdown::REPORT_SLOT => Markdown::runLine($pipeline),
            self::FOLD_END => '',
        ]);

        return (string) preg_replace_callback(
            self::CODE,
            static fn(array $code): string => self::span(html_entity_decode($code[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            (string) preg_replace(self::FOLD, self::FOLDED, $marked),
        );
    }

    /** Text as a code span, fenced by one backtick more than its longest run of them. */
    private static function span(string $code): string
    {
        preg_match_all(self::BACKTICKS, $code, $runs);
        $fence = str_repeat('`', max([0, ...array_map(mb_strlen(...), $runs[0])]) + 1);

        return sprintf('%1$s %2$s %1$s', $fence, $code);
    }
}
