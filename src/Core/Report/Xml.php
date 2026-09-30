<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function htmlspecialchars;
use function mb_scrub;
use function preg_replace;

/**
 * Text made safe for an XML attribute or element: bytes that are not UTF-8
 * replaced, characters XML 1.0 cannot hold dropped, and markup escaped.
 */
final readonly class Xml
{
    private const string UNREPRESENTABLE = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    public static function text(string $text): string
    {
        return htmlspecialchars(
            (string) preg_replace(self::UNREPRESENTABLE, '', mb_scrub($text, 'UTF-8')),
            ENT_XML1 | ENT_QUOTES,
            'UTF-8',
        );
    }
}
