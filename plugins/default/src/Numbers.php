<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault;

use NightWorksIO\MutationGate\Mutator\Mutator;

use const PHP_INT_MAX;

use PhpParser\Node\DeclareItem;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;

/**
 * A number literal one more or one less. The sign of a negative integer is
 * a unary minus around the literal, so the literal moves the other way.
 */
final readonly class Numbers
{
    private const int STEP = 1;

    /** Whether an integer can move: it is below the largest integer, and not a `declare` value. */
    public static function canMove(Int_ $number): bool
    {
        return $number->value < PHP_INT_MAX && ! $number->getAttribute(Mutator::PARENT) instanceof DeclareItem;
    }

    public static function moreInteger(Int_ $number): Int_
    {
        return self::integer($number, self::STEP);
    }

    public static function lessInteger(Int_ $number): Int_
    {
        return self::integer($number, -self::STEP);
    }

    public static function moreFloat(Float_ $number): Float_
    {
        return new Float_($number->value + self::STEP, $number->getAttributes());
    }

    public static function lessFloat(Float_ $number): Float_
    {
        return new Float_($number->value - self::STEP, $number->getAttributes());
    }

    private static function integer(Int_ $number, int $step): Int_
    {
        $literal = $number->getAttribute(Mutator::PARENT) instanceof UnaryMinus ? -$step : $step;

        return new Int_($number->value + $literal, $number->getAttributes());
    }
}
