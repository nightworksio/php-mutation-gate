<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;

/** The constants and the empty array the set's mutators put in place of code, and how they are recognised. */
final readonly class Literals
{
    /** `null`, as a constant and as a type. */
    public const string NULL = 'null';
    private const string TRUE = 'true';

    private const string FALSE = 'false';

    public static function true(): ConstFetch
    {
        return new ConstFetch(new Name(self::TRUE));
    }

    public static function false(): ConstFetch
    {
        return new ConstFetch(new Name(self::FALSE));
    }

    public static function null(): ConstFetch
    {
        return new ConstFetch(new Name(self::NULL));
    }

    /** `[]`, written short. */
    public static function emptyArray(): Array_
    {
        return new Array_([], ['kind' => Array_::KIND_SHORT]);
    }

    /** Whether a constant is `true` as written, in lower case and unqualified. */
    public static function isTrue(ConstFetch $constant): bool
    {
        return $constant->name->toCodeString() === self::TRUE;
    }

    /** Whether a constant is `false` as written, in lower case and unqualified. */
    public static function isFalse(ConstFetch $constant): bool
    {
        return $constant->name->toCodeString() === self::FALSE;
    }

    /** Whether an expression is `null`, in lower case. */
    public static function isNull(Expr $expression): bool
    {
        return $expression instanceof ConstFetch && $expression->name->getFirst() === self::NULL;
    }

    /** Whether an expression is an array literal without items. */
    public static function isEmptyArray(Expr $expression): bool
    {
        return $expression instanceof Array_ && $expression->items === [];
    }
}
