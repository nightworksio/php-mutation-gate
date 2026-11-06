<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * A comparison operator, as PHP spells it, and the one whose comparison is
 * true exactly where its own is false (see Subsumption\Negation).
 *
 * @internal
 */
enum ComparisonOperator: string
{
    case Greater = '>';
    case AtMost = '<=';
    case Less = '<';
    case AtLeast = '>=';
    case Equal = '==';
    case NotEqual = '!=';
    case Identical = '===';
    case NotIdentical = '!==';

    /** The comparison that is true exactly where this one is false. */
    public function opposite(): self
    {
        return match ($this) {
            self::Greater => self::AtMost,
            self::AtMost => self::Greater,
            self::Less => self::AtLeast,
            self::AtLeast => self::Less,
            self::Equal => self::NotEqual,
            self::NotEqual => self::Equal,
            self::Identical => self::NotIdentical,
            self::NotIdentical => self::Identical,
        };
    }
}
