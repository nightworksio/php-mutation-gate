<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function is_string;

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Format\JsonFragment;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function preg_quote;
use function preg_replace;
use function sprintf;

/**
 * The `$schema` a JSON config names, moved to the current release line's
 * published schema where it names another line's (ADR-0026, decision 3). A local
 * path, such as the one `init` writes into `vendor`, is the installed
 * release's own, and stays.
 */
final readonly class PublishedSchema
{
    private const string KEY = '$schema';

    /** The release line in the published schema's URL: `/v0.1/` or `/v1/`. */
    private const string LINE = '~/v\d+(?:\\\\\.\d+)?/~';

    private const string ANY_LINE = '/v\d+(?:\.\d+)?/';

    private const string WHOLE = '~^%s$~';

    /** The config, naming the current line's published schema where it named another's. */
    public static function current(JsonDocument $config): JsonDocument
    {
        $key = KeyPath::of(self::KEY);
        $written = $config->fragment($key);

        return $written instanceof JsonFragment && self::anotherLine($written->scalar())
            ? $config->with($key, JsonFragment::encoding(Definition::PUBLISHED))
            : $config;
    }

    /** Whether a `$schema` is the published schema's URL of a release line other than the current one. */
    private static function anotherLine(string|int|float|bool|NotGiven $schema): bool
    {
        return is_string($schema) && $schema !== Definition::PUBLISHED && preg_match(self::anyLine(), $schema) === 1;
    }

    /** The published schema's URL, of whichever release line. */
    private static function anyLine(): string
    {
        $quoted = preg_quote(Definition::PUBLISHED, '~');

        return sprintf(self::WHOLE, preg_replace(self::LINE, self::ANY_LINE, $quoted));
    }
}
