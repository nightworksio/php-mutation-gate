<?php

declare(strict_types=1);

namespace Library;

use function in_array;

/** The codes a contract names, each in exactly one list. */
final readonly class Codes
{
    public const array DECIDED = [
        Code::Admitted,
        Code::Refused,
    ];

    public const array ANSWERED = [
        Code::Moved,
    ];

    public static function isDecided(Code $code): bool
    {
        return in_array($code, self::DECIDED, strict: true);
    }
}
