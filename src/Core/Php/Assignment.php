<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_last;
use function array_slice;

use PhpToken;

/**
 * A statement that assigns one value to one plain variable, `$name = <value>;`,
 * read from its significant tokens, which end at the `}` of a value that ends
 * in a block, such as a closure's body, and leave out the `;` after it. The
 * variable belongs to the scope it is written in, unless it is one that
 * reaches past it (ReachingVariable).
 */
final readonly class Assignment
{
    /**
     * Whether a statement assigns, by value, to a variable of its own scope a
     * value whose evaluation runs nothing (see Argument::runsNothing),
     * reading at most these variables of the scope's own.
     *
     * @param non-empty-list<PhpToken> $statement
     */
    public static function keepsToItsScope(array $statement, OwnVariables $own): bool
    {
        $variable = $statement[0];
        $rest = array_slice($statement, 1);
        $value = self::valueOf($rest);

        return $variable->is(T_VARIABLE)
            && ! ReachingVariable::tryFrom($variable->text) instanceof ReachingVariable
            && $rest !== []
            && $rest[0]->is('=')
            && $value !== []
            && ! $value[0]->is('&')
            && Argument::of($value)->runsNothing($own);
    }

    /**
     * The value an assignment's tokens after its variable assign: those after
     * the `=`, up to its `;` or to the `}` that ends a value in a block; none
     * where they end otherwise.
     *
     * @param  list<PhpToken> $rest
     * @return list<PhpToken>
     */
    private static function valueOf(array $rest): array
    {
        $ended = $rest !== [] && array_last($rest)->is(';');
        $value = array_slice($rest, 1, $ended ? -1 : null);

        return $ended || ($value !== [] && array_last($value)->is('}')) ? $value : [];
    }
}
