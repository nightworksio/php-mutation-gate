<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use function is_float;
use function is_int;

use NightWorksIO\MutationGate\Core\Migration\MapValue;
use NightWorksIO\MutationGate\Core\NotGiven;
use PhpParser\BuilderHelpers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

/** The literal arguments of a builder call, read and rewritten as a value's change asks (ADR-0026, decision 3). */
final readonly class Literals
{
    /**
     * Whether every argument of the call is a literal, each that holds the
     * retired value now holding the new one; where one is not a literal,
     * nothing is changed.
     */
    public static function mapped(StaticCall|MethodCall $call, MapValue $change): bool
    {
        $old = $change->old()->scalar();
        $new = $change->new()->scalar();

        foreach ($call->args as $argument) {
            if (! $argument instanceof Arg || self::of($argument->value) instanceof NotGiven) {
                return false;
            }
        }

        foreach ($call->args as $argument) {
            if (! $new instanceof NotGiven && self::of($argument->value) === $old) {
                $argument->value = BuilderHelpers::normalizeValue($new);
            }
        }

        return true;
    }

    /** The value a literal holds; none where the expression is no literal. */
    public static function of(Expr $expression): string|int|float|bool|NotGiven
    {
        return match (true) {
            $expression instanceof String_,
            $expression instanceof Int_,
            $expression instanceof Float_ => $expression->value,
            $expression instanceof ConstFetch => self::constant($expression),
            $expression instanceof UnaryMinus => self::negated(self::of($expression->expr)),
            default => NotGiven::value(),
        };
    }

    private static function constant(ConstFetch $constant): bool|NotGiven
    {
        return match ($constant->name->toLowerString()) {
            'true' => true,
            'false' => false,
            default => NotGiven::value(),
        };
    }

    private static function negated(string|int|float|bool|NotGiven $value): int|float|NotGiven
    {
        return is_int($value) || is_float($value) ? -$value : NotGiven::value();
    }
}
