<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function explode;
use function hash;
use function htmlspecialchars;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Visible;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sodium_bin2base64;
use function sprintf;

/**
 * How the HTML report writes what it shows: every text escaped, as a diff,
 * a path or a test name is the project's own and may hold markup; a
 * percentage as every report prints a score; a duration as reports say it.
 *
 * @internal
 */
final readonly class Html
{
    private const string ITEM = '<li>%s</li>';

    private const string CODE = '<code>%s</code>';

    private const string HASH = 'sha256-%s';

    /**
     * Text as HTML shows it, every character that could be read as markup
     * escaped, and every control, separator and format character shown as an
     * escape, so none reorders what the reader sees (see Visible).
     */
    public static function text(string $text): string
    {
        return htmlspecialchars(Visible::text($text), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Prose as HTML shows it, escaped, each part in backticks set as code, as every report writes code in prose. */
    public static function prose(string $text): string
    {
        $parts = [];
        $code = false;

        foreach (explode('`', $text) as $part) {
            $parts[] = $code ? sprintf(self::CODE, self::text($part)) : self::text($part);
            $code = ! $code;
        }

        return implode('', $parts);
    }

    /** The source a Content Security Policy names an inline style, script or style attribute by: its SHA-256. */
    public static function source(string $inline): string
    {
        return sprintf(
            self::HASH,
            sodium_bin2base64(hash('sha256', $inline, binary: true), SODIUM_BASE64_VARIANT_ORIGINAL),
        );
    }

    /** A percentage the JSON report holds, as every report prints a score: `81.37%`. */
    public static function percent(float $percent): string
    {
        return Percent::ofHundredths(Percentage::hundredthsOf($percent));
    }

    public static function seconds(float $seconds): string
    {
        return Seconds::of($seconds)->text();
    }

    /**
     * Some HTML pieces, each an item of a list, the empty ones left out.
     *
     * @param list<string> $pieces
     */
    public static function items(array $pieces): string
    {
        $wrapped = [];

        foreach ($pieces as $piece) {
            $wrapped[] = $piece === '' ? '' : sprintf(self::ITEM, $piece);
        }

        return implode('', $wrapped);
    }
}
