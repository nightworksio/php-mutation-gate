<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/** Where the body of what a declaration declares opens, read from its tokens. */
final readonly class Body
{
    /** The `{` that opens the body declared after an index; none where a `;` ends it bodiless first. */
    public static function after(Tokens $tokens, int $from): int
    {
        foreach ($tokens->indicesOf('{', ';') as $at) {
            if ($at >= $from) {
                return $tokens->is($at, '{') ? $at : Tokens::NONE;
            }
        }

        return Tokens::NONE;
    }
}
