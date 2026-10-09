<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use NightWorksIO\MutationGate\Core\Format\JsonFragment;
use NightWorksIO\MutationGate\Core\Format\Series;

use function sprintf;

/** How each step says what it changed, as a sentence starts. */
final readonly class Changes
{
    private const string BECAME = '%s became %s';

    private const string MAPPED = '%s: %s became %s';

    private const string REMOVED = '%s was removed (%s)';

    public static function became(KeyPath $from, KeyPath $to): string
    {
        return sprintf(self::BECAME, self::key($from), self::key($to));
    }

    public static function mapped(KeyPath $key, JsonFragment $old, JsonFragment $new): string
    {
        return sprintf(self::MAPPED, self::key($key), $old->text(), $new->text());
    }

    public static function split(KeyPath $from, KeyPath ...$to): string
    {
        $keys = [];

        foreach ($to as $key) {
            $keys[] = self::key($key);
        }

        return sprintf(self::BECAME, self::key($from), Series::and(...$keys));
    }

    public static function removed(KeyPath $key, string $because): string
    {
        return sprintf(self::REMOVED, self::key($key), $because);
    }

    private static function key(KeyPath $path): string
    {
        return sprintf('`%s`', $path->value());
    }
}
