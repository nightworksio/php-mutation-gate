<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

/**
 * A character visible text writes as a short escape, by the character it stands for.
 *
 * @internal
 */
enum ShortEscape: string
{
    case Backslash = '\\';
    case LineFeed = "\n";
    case CarriageReturn = "\r";
    case Tab = "\t";

    /** The escape the character is written as. */
    public function written(): string
    {
        return match ($this) {
            self::Backslash => '\\\\',
            self::LineFeed => '\n',
            self::CarriageReturn => '\r',
            self::Tab => '\t',
        };
    }
}
