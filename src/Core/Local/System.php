<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

use const PHP_OS_FAMILY;

/**
 * The family of operating systems the gate runs on, as `PHP_OS_FAMILY`
 * names it, where the local loop treats it apart (ADR-0120).
 */
enum System: string
{
    case Darwin = 'Darwin';

    case Linux = 'Linux';

    case Windows = 'Windows';

    /** Any other: BSD, Solaris, or one PHP cannot name. */
    case Other = 'Other';

    public static function here(): self
    {
        return self::named(PHP_OS_FAMILY);
    }

    /** The family `PHP_OS_FAMILY` gives this name. */
    public static function named(string $family): self
    {
        return self::tryFrom($family) ?? self::Other;
    }
}
