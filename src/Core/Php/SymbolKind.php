<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * What a line that is not executable declares, and so what can read the value
 * a mutant there changes, and a global constant, whose line is executable but
 * whose value is read wherever its name is. A named constant, case, property,
 * plain function's parameter or attribute's argument can be followed to the
 * code that reads it; a method's or a closure's parameter is always unnamed.
 *
 * @internal
 */
enum SymbolKind: string
{
    case Constant = 'constant';
    case GlobalConstant = 'global constant';
    case EnumCase = 'enum case';
    case StaticProperty = 'static property';
    case Property = 'property';
    case FunctionParameter = 'function parameter';
    case MethodParameter = 'method parameter';
    case ClosureParameter = 'closure parameter';
    case AttributeArgument = 'attribute argument';

}
