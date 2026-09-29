<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function count;
use function in_array;
use function is_array;

use NightWorksIO\MutationGate\Core\File\Contents;

use function token_get_all;

/**
 * The lines of PHP that hold code: each line with a token that is not
 * whitespace, a comment or the opening tag. A line holding only a brace or a
 * semicolon counts for nothing, because the tokenizer gives those tokens with
 * no line of their own.
 */
final readonly class LinesOfCode
{
    /** What a line may hold and still hold no code. */
    private const array SILENT = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG];

    public static function in(Contents $source): int
    {
        $lines = [];

        foreach (token_get_all($source->text()) as $token) {
            if (is_array($token) && ! in_array($token[0], self::SILENT, strict: true)) {
                $lines[$token[2]] = $token[2];
            }
        }

        return count($lines);
    }
}
