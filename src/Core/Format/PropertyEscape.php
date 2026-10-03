<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

/** The letters a properties file escapes a control character with, after a `\`. */
enum PropertyEscape: string
{
    case Tab = 't';
    case LineFeed = 'n';
    case CarriageReturn = 'r';
    case FormFeed = 'f';

    /** The control character the escape stands for. */
    public function character(): string
    {
        return match ($this) {
            self::Tab => "\t",
            self::LineFeed => "\n",
            self::CarriageReturn => "\r",
            self::FormFeed => "\f",
        };
    }
}
