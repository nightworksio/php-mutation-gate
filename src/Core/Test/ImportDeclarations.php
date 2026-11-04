<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_keys;
use function array_last;
use function explode;

use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\Scope;
use NightWorksIO\MutationGate\Core\Php\Tokens;

/**
 * Each import a file declares at its top, outside any namespace's braces: a
 * `use` that begins a statement, up to its `;`, by its tokens spelt with one
 * space between each two that whitespace parts, so a change of layout alone
 * leaves it the same import, with the name each of its clauses gives: a
 * name that ends a clause, its alias where it has one. A trait's `use` and a
 * closure's are none.
 *
 * @internal
 */
final readonly class ImportDeclarations
{
    /** @return list<Import> */
    public static function in(Tokens $tokens): array
    {
        $imports = [];

        foreach ($tokens->indicesOf(T_USE) as $at) {
            $end = self::endOf($tokens, $at);
            $starts = $at === 0 || $tokens->is($at - 1, ...Tokens::STATEMENT_BOUNDS);

            if ($starts && $tokens->enclosing($at) === Tokens::NONE && $end !== Tokens::NONE) {
                $imports[] = new Import(
                    Declared::between($tokens->spelt($at, $end + 1), $tokens, $at, $end),
                    self::namesOf($tokens, $at, $end),
                );
            }
        }

        return $imports;
    }

    /**
     * The name each clause of the import from one index to another gives.
     *
     * @return list<string>
     */
    private static function namesOf(Tokens $tokens, int $at, int $end): array
    {
        $names = [];

        foreach (array_keys($tokens->between($at, $end)) as $name) {
            if ($tokens->is($name, ...Names::TOKENS) && $tokens->is($name + 1, ...Scope::CLAUSE_ENDS)) {
                $names[] = array_last(explode('\\', $tokens->text($name)));
            }
        }

        return $names;
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
