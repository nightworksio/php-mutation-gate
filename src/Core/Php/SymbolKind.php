<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * What a line that is not executable declares, and so what can read the value
 * a mutant there changes. Only the first five can be followed to the code that
 * reads them; the rest have no reference a token scan can follow.
 */
enum SymbolKind: string
{
    case Constant = 'constant';
    case EnumCase = 'enum case';
    case StaticProperty = 'static property';
    case Property = 'property';
    case FunctionParameter = 'function parameter';
    case MethodParameter = 'method parameter';
    case ClosureParameter = 'closure parameter';
    case AttributeArgument = 'attribute argument';

    /** Whether a token scan can find what reads a value of this kind. */
    public function isFollowable(): bool
    {
        return match ($this) {
            self::MethodParameter, self::ClosureParameter, self::AttributeArgument => false,
            self::Constant, self::EnumCase, self::StaticProperty, self::Property, self::FunctionParameter => true,
        };
    }
}
