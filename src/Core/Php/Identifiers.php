<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_key_exists;

use PhpToken;

use function preg_match;

/**
 * A file's significant tokens, each semi-reserved word that stands as a name
 * read as one. PHP lets a class constant, an enum case and a method take a
 * word such as `GLOBAL`, `Default` or `and` as its name, and tokenizes it as
 * that keyword where it is declared and after `::`; the readers look for a
 * `T_STRING` there.
 */
final readonly class Identifiers
{
    /** How a name is spelt: a letter or underscore, then letters, digits and underscores. */
    private const string WORD = '/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/';

    /** What may stand after an enum case's name. */
    private const array AFTER_A_CASE = ['=', ';'];

    /**
     * @param list<PhpToken> $tokens a file's significant tokens, in order
     *
     * @return list<PhpToken>
     */
    public static function of(array $tokens): array
    {
        $named = [];

        foreach ($tokens as $at => $token) {
            $named[] = self::standsAsAName($tokens, $at)
                ? new PhpToken(T_STRING, $token->text, $token->line, $token->pos)
                : $token;
        }

        return $named;
    }

    /** @param list<PhpToken> $tokens */
    private static function standsAsAName(array $tokens, int $at): bool
    {
        $token = $tokens[$at];

        return ! $token->is(T_STRING)
            && preg_match(self::WORD, $token->text) === 1
            && (self::member($tokens, $at) || self::declared($tokens, $at));
    }

    /**
     * Whether the word is a member's name read through `::`, which `::class` is not.
     *
     * @param list<PhpToken> $tokens
     */
    private static function member(array $tokens, int $at): bool
    {
        return self::is($tokens, $at - 1, T_DOUBLE_COLON) && ! $tokens[$at]->is(T_CLASS);
    }

    /**
     * Whether the word is the name a function, a constant or an enum case
     * declares: after `function`, past a `&`; after `const`, past a type,
     * before its value; or after `case`, before its value or its end.
     *
     * @param list<PhpToken> $tokens
     */
    private static function declared(array $tokens, int $at): bool
    {
        return self::is($tokens, $at - 1, T_FUNCTION)
            || (self::is($tokens, $at - 1, '&') && self::is($tokens, $at - 2, T_FUNCTION))
            || (self::is($tokens, $at + 1, '=') && self::is($tokens, $at - 1, T_CONST))
            || (self::is($tokens, $at + 1, '=') && self::is($tokens, $at - 2, T_CONST))
            || (self::is($tokens, $at - 1, T_CASE) && self::is($tokens, $at + 1, ...self::AFTER_A_CASE));
    }

    /** @param list<PhpToken> $tokens */
    private static function is(array $tokens, int $at, int|string ...$kinds): bool
    {
        return array_key_exists($at, $tokens) && $tokens[$at]->is($kinds);
    }
}
