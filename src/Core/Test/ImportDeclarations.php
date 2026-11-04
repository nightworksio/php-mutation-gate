<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_keys;

use NightWorksIO\MutationGate\Core\Php\Tokens;

/**
 * Each import a file declares at its top, outside any namespace's braces: a
 * `use` that begins a statement, up to its `;`, by its tokens spelt with one
 * space between each two that whitespace parts, so a change of layout alone
 * leaves it the same import. A trait's `use` and a closure's are none.
 *
 * @internal
 */
final readonly class ImportDeclarations
{
    /** @return list<Declared> */
    public static function in(Tokens $tokens): array
    {
        $imports = [];

        foreach ($tokens->indicesOf(T_USE) as $at) {
            $end = self::endOf($tokens, $at);
            $starts = $at === 0 || $tokens->is($at - 1, ...Tokens::STATEMENT_BOUNDS);

            if ($starts && $tokens->enclosing($at) === Tokens::NONE && $end !== Tokens::NONE) {
                $imports[] = Declared::between($tokens->spelt($at, $end + 1), $tokens, $at, $end);
            }
        }

        return $imports;
    }

    /** Where the `;` that ends the statement at an index stands, outside every bracket; none where none does. */
    private static function endOf(Tokens $tokens, int $at): int
    {
        foreach (array_keys($tokens->between($at, $tokens->count())) as $end) {
            if ($tokens->is($end, ';') && $tokens->enclosing($end) === Tokens::NONE) {
                return $end;
            }
        }

        return Tokens::NONE;
    }
}
