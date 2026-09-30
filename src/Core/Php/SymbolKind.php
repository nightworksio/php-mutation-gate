<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * What a line that is not executable declares, and so what can read the value
 * a mutant there changes. A named constant, case, property or plain
 * function's parameter can be followed to the code that reads it; a method's
 * or a closure's parameter and an attribute's argument are always unnamed.
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

}
