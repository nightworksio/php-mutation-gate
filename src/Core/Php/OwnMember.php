<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function mb_strtolower;

/**
 * How a class names itself and its parent, and calls its own members:
 * `$this->m()`, `self::m()`, `static::m()` or `parent::m()`.
 */
final readonly class OwnMember
{
    /** The variable a class calls its own methods on. */
    private const string THIS = ReachingVariable::This->value;

    /** How a class names itself, besides `static`. */
    private const string SELF = 'self';

    /** How a class names the class it extends. */
    private const string PARENT = 'parent';

    /** Whether the `->` or `::` at an index follows what a class calls its own members on. */
    public static function calledAt(Tokens $tokens, int $operator): bool
    {
        $on = $operator - 1;
        $itself = $tokens->is($on, T_STATIC) || self::isSelf($tokens, $on) || self::isParent($tokens, $on);

        return $tokens->is($operator, T_OBJECT_OPERATOR)
            ? $tokens->is($on, T_VARIABLE) && $tokens->text($on) === self::THIS
            : $tokens->is($operator, T_DOUBLE_COLON) && $itself;
    }

    /** Whether the token at an index is `self`, in any case, as PHP reads it. */
    public static function isSelf(Tokens $tokens, int $at): bool
    {
        return $tokens->is($at, T_STRING) && mb_strtolower($tokens->text($at)) === self::SELF;
    }

    /** Whether the token at an index is `parent`, in any case, as PHP reads it. */
    public static function isParent(Tokens $tokens, int $at): bool
    {
        return $tokens->is($at, T_STRING) && mb_strtolower($tokens->text($at)) === self::PARENT;
    }
}
