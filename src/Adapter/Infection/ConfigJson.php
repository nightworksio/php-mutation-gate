<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function implode;
use function json_encode;
use function sprintf;

/**
 * The JSON of the config the gate writes, put together from the JSON of each
 * of its members: the project's own, as they were read, and the gate's.
 */
final readonly class ConfigJson
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * An object of these members, an empty one where there are none.
     *
     * @param array<string, string> $members the JSON of each member, by its key
     */
    public static function object(array $members): string
    {
        $written = [];

        foreach ($members as $key => $json) {
            $written[] = sprintf('%s:%s', self::value($key), $json);
        }

        return sprintf('{%s}', implode(',', $written));
    }

    /** @param string|float|list<string> $value */
    public static function value(string|float|array $value): string
    {
        return json_encode($value, self::FLAGS);
    }
}
