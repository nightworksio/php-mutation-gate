<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;

use function sprintf;

/**
 * The attempts for a survivor that changed an operator between a variable
 * and a value (ADR-0085): a boundary at its value, then one step past it;
 * an equality at the value it compares, then at any other; arithmetic with
 * each variable at a value that is neither 0, 1 nor the other operand; and
 * a logical operator between two variables with exactly one of them true.
 *
 * @internal
 */
final readonly class Compared
{
    /** Values that are neither 0 nor 1, and differ, so no operand is an identity and no two results agree by chance. */
    private const string FIRST = '3';

    private const string SECOND = '5';

    private const string NUMBER = '%d';

    public static function boundary(
        Operands|NotGiven $sides,
        Contents|Missing $source,
        Enclosing|Nameless $function,
    ): Attempt|NotGiven {
        if ($sides instanceof NotGiven) {
            return $sides;
        }

        $right = Values::integer($sides->right, $source);
        $leftSide = Values::isVariable($sides->left) && is_int($right);
        $variable = $leftSide ? $sides->left : $sides->right;
        $value = $leftSide ? $right : Values::integer($sides->left, $source);
        $operator = Comparison::from($sides->operator);

        return Values::isVariable($variable) && is_int($value)
            ? self::around($function, $variable, $value, $leftSide ? $operator : $operator->mirrored())
            : NotGiven::value();
    }

    public static function equality(Operands|NotGiven $sides, Enclosing|Nameless $function): Attempt|NotGiven
    {
        if ($sides instanceof NotGiven) {
            return $sides;
        }

        $right = Values::literal($sides->right);
        $variable = Values::isVariable($sides->left) && is_string($right) ? $sides->left : $sides->right;
        $value = $variable === $sides->left ? $right : Values::literal($sides->left);

        return Values::isVariable($variable) && is_string($value)
            ? Attempts::calling($function, ', then at any other value.', [$variable => $value])
            : NotGiven::value();
    }

    public static function arithmetic(Operands|NotGiven $sides, Enclosing|Nameless $function): Attempt|NotGiven
    {
        if ($sides instanceof NotGiven) {
            return $sides;
        }

        $left = Values::isVariable($sides->left);
        $right = Values::isVariable($sides->right);
        $other = Values::literal($left ? $sides->right : $sides->left);
        $alone = $other === self::FIRST ? self::SECOND : self::FIRST;

        return match (true) {
            $left && $right => Attempts::calling(
                $function,
                '.',
                [$sides->left => self::FIRST, $sides->right => self::SECOND],
            ),
            $left && is_string($other) => Attempts::calling($function, '.', [$sides->left => $alone]),
            $right && is_string($other) => Attempts::calling($function, '.', [$sides->right => $alone]),
            default => NotGiven::value(),
        };
    }

    public static function logical(Operands|NotGiven $sides, Enclosing|Nameless $function): Attempt|NotGiven
    {
        if ($sides instanceof NotGiven || ! Values::isVariable($sides->left) || ! Values::isVariable($sides->right)) {
            return NotGiven::value();
        }

        return Attempts::calling(
            $function,
            ', then the other way round.',
            [$sides->left => 'true', $sides->right => 'false'],
            [$sides->left => 'false', $sides->right => 'true'],
        );
    }

    /** The attempt at a boundary's value, then one step past it. */
    private static function around(
        Enclosing|Nameless $function,
        string $variable,
        int $value,
        Comparison $operator,
    ): Attempt {
        $past = $operator->pastOf($value);

        return Attempts::calling(
            $function,
            sprintf(', then at %d.', $past),
            [$variable => sprintf(self::NUMBER, $value)],
            [$variable => sprintf(self::NUMBER, $past)],
        );
    }
}
