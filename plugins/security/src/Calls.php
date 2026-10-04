<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSecurity;

use function array_key_exists;
use function array_map;
use function in_array;
use function mb_strtolower;

use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;

/**
 * The calls of PHP's own functions, by the bare name code calls them by, as
 * written or with a leading backslash, in any case, as PHP matches them.
 */
final readonly class Calls
{
    /** The call, where a node calls one of these functions; none for any other node. */
    public static function ofFunction(Node $node, string ...$functions): FuncCall|false
    {
        return $node instanceof FuncCall
            && $node->name instanceof Name
            && in_array($node->name->toLowerString(), array_map(mb_strtolower(...), $functions), strict: true)
            ? $node
            : false;
    }

    /** The value a call of one of these functions passes first, by position; unchanged for any other node. */
    public static function unwrapped(Node $node, string ...$functions): Expr|Unchanged
    {
        $call = self::ofFunction($node, ...$functions);
        $value = $call === false ? false : self::argument($call, 0);

        return $value instanceof Expr ? $value : Unchanged::node();
    }

    /** The value of a call's argument at a position, where the call passes one there by position. */
    public static function argument(FuncCall $call, int $position): Expr|false
    {
        $arguments = $call->getRawArgs();

        return array_key_exists($position, $arguments) && $arguments[$position] instanceof Arg
            ? $arguments[$position]->value
            : false;
    }
}
