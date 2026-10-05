<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_keys;
use function array_map;
use function explode;
use function htmlspecialchars;
use function implode;
use function max;
use function mb_scrub;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\Format\Fit;

use function preg_match_all;
use function preg_replace;
use function sprintf;
use function str_repeat;
use function strtr;

/**
 * Text the project under test controls, such as a path, a diff or a test's
 * name, made inert in GitHub Markdown: no markup, no link, bare address
 * included, no mention, no table cell or fence it can end, and no control or
 * format character, a bidirectional override included.
 */
final readonly class Escape
{
    /** Each character Markdown or HTML would read as syntax, and the entity that shows it instead. */
    private const array ENTITIES = [
        '@' => '&#64;',
        '|' => '&#124;',
        '[' => '&#91;',
        ']' => '&#93;',
        '*' => '&#42;',
        '_' => '&#95;',
        '~' => '&#126;',
        '\\' => '&#92;',
        '#' => '&#35;',
        '!' => '&#33;',
        '`' => '&#96;',
        "\r" => ' ',
        "\n" => ' ',
    ];

    /**
     * What GitHub makes a link of in plain text, a scheme's `://` and a host's `www.`, and the same with the
     * slashes and the dot written as entities, which GitHub shows as they are and links nowhere.
     */
    private const array ADDRESSES = ['#://#' => ':&#47;&#47;', '#\\b(www)\\.#i' => '$1&#46;'];

    /** A control or format character but a tab and a line break, which a block shows as they are. */
    private const string UNSHOWN = '/[^\\P{Cc}\\t\\n]|\\p{Cf}/u';

    /** The shortest fence a code block takes. */
    private const string FENCE = '~~~';

    /** Prose, whose backticked parts, as a hint writes code, are shown as code. */
    public static function text(string $text): string
    {
        $escaped = [];

        foreach (explode('`', $text) as $at => $part) {
            $escaped[] = $at % 2 === 1 ? self::code($part) : self::plain($part);
        }

        return implode('', $escaped);
    }

    /** Text shown as inline code. */
    public static function code(string $text): string
    {
        return sprintf('<code>%s</code>', self::plain($text));
    }

    /** Text shown as a block of code, in a fence longer than any run of its fence character inside it. */
    public static function block(string $text, string $language): string
    {
        $shown = preg_replace(self::UNSHOWN, '', mb_scrub($text, 'UTF-8')) ?? '';
        preg_match_all('/~+/', $shown, $runs);
        $longest = max([0, ...array_map(mb_strlen(...), $runs[0])]);
        $fence = str_repeat('~', max(mb_strlen(self::FENCE), $longest + 1));

        return sprintf("%s%s\n%s\n%s", $fence, $language, $shown, $fence);
    }

    private static function plain(string $text): string
    {
        $escaped = strtr(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), self::ENTITIES);

        return Fit::verbatim(preg_replace(array_keys(self::ADDRESSES), self::ADDRESSES, $escaped) ?? '');
    }
}
