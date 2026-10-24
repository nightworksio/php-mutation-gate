<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use LogicException;

/**
 * PCRE could not escape a value for Visible. Its patterns are fixed character
 * classes and the text is valid UTF-8 once scrubbed, so reaching this is a
 * mistake in one of its patterns.
 */
final class NotEscaped extends LogicException
{
    public static function byPcre(): self
    {
        return new self('PCRE could not escape a value that is valid UTF-8 against a fixed character class.');
    }
}
