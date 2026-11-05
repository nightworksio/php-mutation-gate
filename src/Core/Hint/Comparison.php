<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use function array_map;

/**
 * An operator a boundary mutant changes (ADR-0101): where its sides swap,
 * and whether one step past its boundary is below the value or above it.
 *
 * @internal
 */
enum Comparison: string
{
    case Less = '<';
    case LessOrEqual = '<=';
    case Greater = '>';
    case GreaterOrEqual = '>=';

    /** @return list<string> every operator, as it is spelt */
    public static function spelt(): array
    {
        return array_map(static fn(self $comparison): string => $comparison->value, self::cases());
    }

    /** This operator with its sides swapped: `10 < $x` is `$x > 10`. */
    public function mirrored(): self
    {
        return match ($this) {
            self::Less => self::Greater,
            self::LessOrEqual => self::GreaterOrEqual,
            self::Greater => self::Less,
            self::GreaterOrEqual => self::LessOrEqual,
        };
    }

    /** The value one step past a boundary at this one: below it for `<` and `>=`, above it for `<=` and `>`. */
    public function pastOf(int $boundary): int
    {
        return match ($this) {
            self::Less, self::GreaterOrEqual => $boundary - 1,
            self::LessOrEqual, self::Greater => $boundary + 1,
        };
    }
}
