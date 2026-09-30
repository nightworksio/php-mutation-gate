<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function implode;
use function json_encode;
use function sprintf;

/**
 * JSON put together from the JSON of each member: members read from a file
 * as they were, beside values the gate writes itself.
 */
final readonly class JsonObject
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * An object of these members, an empty one where there are none.
     *
     * @param array<array-key, string> $members the JSON of each member, by its key
     */
    public static function of(array $members): string
    {
        $written = [];

        foreach ($members as $key => $json) {
            $written[] = sprintf('%s:%s', self::value(sprintf('%s', $key)), $json);
        }

        return sprintf('{%s}', implode(',', $written));
    }

    /** @param string|float|list<string> $value */
    public static function value(string|float|array $value): string
    {
        return json_encode($value, self::FLAGS);
    }
}
