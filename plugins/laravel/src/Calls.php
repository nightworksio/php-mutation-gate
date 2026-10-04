<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel;

use function array_key_exists;
use function array_map;
use function in_array;
use function mb_strtolower;

use NightWorksIO\MutationGate\Mutator\Receiver;
use NightWorksIO\MutationGate\Mutator\ResolvedName;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

use function sprintf;

/**
 * The calls Laravel code makes, matched on syntax and resolved names alone: a
 * facade's static method by the facade's full name or by the global alias
 * `config/app.php` registers for it, a helper function by its bare name, and
 * a method called on `$this`. Names are matched as PHP matches them, in any
 * case.
 */
final readonly class Calls
{
    /** Where Laravel's facades live, each under its own name. */
    private const string FACADE = 'Illuminate\\Support\\Facades\\%s';

    /** Whether a node calls one of these methods statically on a facade. */
    public static function onFacade(Node $node, Facade $facade, string ...$methods): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Name
            && self::isFacade($node->class, $facade)
            && self::named($node->name, ...$methods);
    }

    /** Whether a class name stands for a facade, by its full name or its global alias. */
    public static function isFacade(Name $name, Facade $facade): bool
    {
        return in_array(ResolvedName::of($name), [sprintf(self::FACADE, $facade->value), $facade->value], strict: true);
    }

    /** Whether a node calls one of these functions by its bare name, as Laravel's helpers are called. */
    public static function ofFunction(Node $node, string ...$functions): bool
    {
        return $node instanceof FuncCall
            && $node->name instanceof Name
            && self::listed($node->name->toLowerString(), $functions);
    }

    /** Whether a node calls one of these methods on `$this`. */
    public static function onThis(Node $node, string ...$methods): bool
    {
        return $node instanceof MethodCall
            && Receiver::isThis($node->var)
            && self::named($node->name, ...$methods);
    }

    /** Whether a call's name, as written, is one of these. */
    public static function named(Identifier|Expr $name, string ...$methods): bool
    {
        return $name instanceof Identifier && self::listed($name->toLowerString(), $methods);
    }

    /** The value of a call's argument at a position, where the call passes one there by position. */
    public static function argument(FuncCall|MethodCall|StaticCall $call, int $position): Expr|false
    {
        $arguments = $call->getRawArgs();

        return array_key_exists($position, $arguments) && $arguments[$position] instanceof Arg
            ? $arguments[$position]->value
            : false;
    }

    /** @param array<string> $names */
    private static function listed(string $lowered, array $names): bool
    {
        return in_array($lowered, array_map(mb_strtolower(...), $names), strict: true);
    }
}
