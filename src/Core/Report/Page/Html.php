<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function htmlspecialchars;
use function implode;
use function intval;

use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function round;
use function sprintf;

/**
 * How the HTML report writes what it shows: every text escaped, as a diff,
 * a path or a test name is the project's own and may hold markup; a
 * percentage as every report prints a score; a duration as reports say it.
 */
final readonly class Html
{
    /** The hundredths in a percent, as a score is held. */
    private const int HUNDREDTHS = 100;

    private const string ITEM = '<li>%s</li>';

    /** Text as HTML shows it, every character that could be read as markup escaped. */
    public static function text(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A percentage the JSON report holds, as every report prints a score: `81.37%`. */
    public static function percent(float $percent): string
    {
        return Percent::ofHundredths(self::hundredths($percent));
    }

    /** A percentage the JSON report holds, in the hundredths of a percent a score is held in. */
    public static function hundredths(float $percent): int
    {
        return intval(round($percent * self::HUNDREDTHS));
    }

    public static function seconds(float $seconds): string
    {
        return Seconds::of($seconds)->text();
    }

    /** An element holding some HTML, with a class. */
    public static function element(string $name, string $class, string $html): string
    {
        return sprintf('<%1$s class="%2$s">%3$s</%1$s>', $name, $class, $html);
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
