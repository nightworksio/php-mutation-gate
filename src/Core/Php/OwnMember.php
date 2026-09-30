<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function mb_strtolower;

/** How a class calls its own members: `$this->m()`, `self::m()` or `static::m()`. */
final readonly class OwnMember
{
    /** The variable a class calls its own methods on. */
    private const string THIS = '$this';

    /** The class a class calls its own static methods on, besides `static`. */
    private const string SELF = 'self';

    /** Whether the `->` or `::` at an index follows what a class calls its own members on. */
    public static function calledAt(Tokens $tokens, int $operator): bool
    {
        $on = $operator - 1;

        $self = $tokens->is($on, T_STATIC)
            || ($tokens->is($on, T_STRING) && mb_strtolower($tokens->text($on)) === self::SELF);

        return $tokens->is($operator, T_OBJECT_OPERATOR)
            ? $tokens->is($on, T_VARIABLE) && $tokens->text($on) === self::THIS
            : $tokens->is($operator, T_DOUBLE_COLON) && $self;
    }
}
