<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;

/** What a method is called on, as a mutator recognises it. */
final readonly class Receiver
{
    private const string THIS = 'this';

    /** Whether an expression is `$this`. */
    public static function isThis(Expr $expression): bool
    {
        return $expression instanceof Variable && $expression->name === self::THIS;
    }
}
