<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

use function preg_match;

/** A whole number as text writes it: digits alone, the first of them no nought. */
final readonly class WholeNumber
{
    private const string POSITIVE = '/^[1-9]\d*$/D';

    private const string DIGITS = '/\A\d+\z/';

    /** Whether the text writes a whole number of one or more, and nothing else. */
    public static function isPositive(string $text): bool
    {
        return preg_match(self::POSITIVE, $text) === 1;
    }

    /** Whether the text writes a whole number of nought or more in digits alone, leading noughts among them. */
    public static function isDigits(string $text): bool
    {
        return preg_match(self::DIGITS, $text) === 1;
    }
}
