<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * A word an operand of a unary operator stops at, however it is spelt: a
 * logical operator spelt out, or `as` (see Subsumption\Negation).
 *
 * @internal
 */
enum OperandStop: string
{
    case And = 'and';
    case Or = 'or';
    case Xor = 'xor';
    case As = 'as';
}
