<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function preg_replace;
use function sprintf;
use function str_replace;
use function substr;

/**
 * A server database's copy's name: the start of the original's letters,
 * digits and underscores, then the token that keeps it the run's own,
 * within the 63 bytes PostgreSQL and the 64 MySQL take; and a name quoted
 * as an identifier.
 *
 * @internal
 */
final readonly class CopyName
{
    private const string NAME = '%s_mg_%s';

    /** What of the original's name a copy's name leaves out. */
    private const string LEFT_OUT = '/[^A-Za-z0-9_]/';

    /** How much of the original's name a copy's name keeps. */
    private const int KEPT = 40;

    public static function of(string $original, string $token): string
    {
        return sprintf(self::NAME, substr((string) preg_replace(self::LEFT_OUT, '', $original), 0, self::KEPT), $token);
    }

    /** A name quoted between this mark, each such mark in it doubled. */
    public static function quoted(string $name, string $mark): string
    {
        return sprintf('%1$s%2$s%1$s', $mark, str_replace($mark, sprintf('%1$s%1$s', $mark), $name));
    }
}
