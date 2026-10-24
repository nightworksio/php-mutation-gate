<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use LogicException;

/**
 * A step of Format's that cannot fail on what it is handed failed: PCRE on a
 * fixed pattern over text scrubbed to valid UTF-8, gzip packing at its
 * default level, or decoding a key of text already read as JSON. Reaching
 * this is a mistake in the code, not in what it was handed.
 */
final class FormatFault extends LogicException
{
    private const string PCRE = 'PCRE could not apply a fixed pattern to text that is valid UTF-8.';

    private const string GZIP = 'zlib could not pack text at its default level.';

    private const string JSON_KEY = 'A key of text already read as JSON did not decode to a string.';

    public static function pcre(): self
    {
        return new self(self::PCRE);
    }

    public static function gzip(): self
    {
        return new self(self::GZIP);
    }

    public static function jsonKey(): self
    {
        return new self(self::JSON_KEY);
    }
}
