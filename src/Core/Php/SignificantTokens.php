<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use NightWorksIO\MutationGate\Core\File\Contents;
use PhpToken;

/**
 * A PHP file's significant tokens, in order: every token but whitespace,
 * comments and the tag that closes its PHP.
 *
 * @internal
 */
final readonly class SignificantTokens
{
    /** @return list<PhpToken> */
    public static function of(Contents $contents): array
    {
        $tokens = [];

        foreach (PhpToken::tokenize($contents->text()) as $token) {
            if (! $token->isIgnorable() && ! $token->is(T_CLOSE_TAG)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
