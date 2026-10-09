<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function is_string;

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Format\JsonFragment;

use function preg_match;
use function preg_quote;
use function preg_replace;
use function sprintf;

/**
 * The `$schema` a JSON config names, moved to the current major's published
 * schema where it names another major's (ADR-0026, decision 3). A local
 * path, such as the one `init` writes into `vendor`, is the installed
 * release's own, and stays.
 */
final readonly class PublishedSchema
{
    private const string KEY = '$schema';

    /** The major in the published schema's URL: `/v1/`. */
    private const string MAJOR = '~/v\d+/~';

    private const string ANY_MAJOR = '/v\d+/';

    private const string WHOLE = '~^%s$~';

    /** The config, naming the current major's published schema where it named another's. */
    public static function current(JsonDocument $config): JsonDocument
    {
        $key = KeyPath::of(self::KEY);
        $written = $config->fragment($key);
        $schema = $written instanceof JsonFragment ? $written->scalar() : '';

        return is_string($schema) && $schema !== Definition::PUBLISHED && preg_match(self::anyMajor(), $schema) === 1
            ? $config->with($key, JsonFragment::encoding(Definition::PUBLISHED))
            : $config;
    }

    /** The published schema's URL, of whichever major. */
    private static function anyMajor(): string
    {
        $quoted = preg_quote(Definition::PUBLISHED, '~');

        return sprintf(self::WHOLE, preg_replace(self::MAJOR, self::ANY_MAJOR, $quoted));
    }
}
