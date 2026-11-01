<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function ltrim;
use function mb_strtolower;

/**
 * Where one file reads a global constant: a name that stands as a value, as
 * the file may resolve it, through its namespace, its imports and the global
 * namespace, and `constant()` with the name written out. A name before `(`,
 * `::` or a variable, or after `new`, `->`, `as` or a keyword that declares
 * or imports, is no read. A `constant()` of a name not written out is a read the
 * scan cannot follow.
 *
 * @internal
 */
final readonly class GlobalReads
{
    /** What stands before a name that is no read of a constant. */
    private const array NOT_AFTER = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_NEW,
        T_CONST,
        T_USE,
        T_AS,
        T_NAMESPACE,
        T_INSTANCEOF,
        T_EXTENDS,
        T_IMPLEMENTS,
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
        T_ENUM,
        T_GOTO,
    ];

    /** What follows a name that is no read of a constant: a call, a class's member, or a type before a variable. */
    private const array NOT_BEFORE = ['(', T_DOUBLE_COLON, T_VARIABLE];

    public static function of(Symbol $symbol, Source $source): References
    {
        $tokens = $source->tokens();
        $constant = Names::of($symbol->owner());
        $found = References::none();

        foreach ($tokens->indicesOf(...Names::TOKENS) as $at) {
            $found = match (true) {
                self::isLookup($tokens, $at) => $found->and(self::lookedUp($tokens, $at, $constant, $source)),
                $tokens->is($at + 1, ...self::NOT_BEFORE), $tokens->is($at - 1, ...self::NOT_AFTER) => $found,
                self::candidates($source->scope(), $tokens->text($at))->meet($constant) => $found->and(
                    References::at(Site::in($source, $at)),
                ),
                default => $found,
            };
        }

        return $found;
    }

    /** Whether a name is a call of PHP's `constant()`. */
    private static function isLookup(Tokens $tokens, int $at): bool
    {
        return mb_strtolower(ltrim($tokens->text($at), '\\')) === Reads::LOOKUP
            && $tokens->is($at + 1, '(')
            && ! $tokens->is($at - 1, ...self::NOT_AFTER);
    }

    /**
     * A `constant()` call: a read where it names the constant, none where it
     * names another, and unknown where no name is written out.
     */
    private static function lookedUp(Tokens $tokens, int $call, Names $constant, Source $source): References
    {
        $argument = $call + Tokens::ARGUMENT;

        if (! $tokens->is($argument, T_CONSTANT_ENCAPSED_STRING) || ! $tokens->is($call + StaticReads::CLOSE, ')')) {
            return References::unknown();
        }

        return Names::of(ltrim($tokens->unquoted($argument), '\\'))->meet($constant)
            ? References::at(Site::in($source, $call))
            : References::none();
    }

    /**
     * Every constant a name written in a file may stand for: as it resolves
     * there, through any import of its alias, and, unqualified, the
     * namespace's own and the global one, as PHP falls back to it.
     */
    private static function candidates(Scope $scope, string $name): Names
    {
        return $scope->resolve($name)->merge($scope->resolveAny($name), Names::of($scope->declared($name), $name));
    }
}
