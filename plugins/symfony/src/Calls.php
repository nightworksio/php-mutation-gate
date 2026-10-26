<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSymfony;

use function array_map;
use function in_array;
use function mb_strtolower;

use NightWorksIO\MutationGate\Mutator\Receiver;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;

/**
 * The calls Symfony code makes, matched on syntax alone: a method by its name
 * on any receiver, where the name is Symfony's own, or on `$this`, as a
 * controller calls `AbstractController`'s helpers. Names are matched as PHP
 * matches them, in any case.
 */
final readonly class Calls
{
    /** Whether a node calls this method, on any receiver. */
    public static function ofMethod(Node $node, string $method): bool
    {
        return $node instanceof MethodCall && self::named($node->name, $method);
    }

    /** Whether a node calls this method on `$this`. */
    public static function onThis(Node $node, string $method): bool
    {
        return $node instanceof MethodCall && Receiver::isThis($node->var) && self::named($node->name, $method);
    }

    /** Whether a name, as written, is one of these, as PHP compares names. */
    public static function named(Identifier|Expr|string $name, string ...$names): bool
    {
        return ! $name instanceof Expr
            && in_array(mb_strtolower((string) $name), array_map(mb_strtolower(...), $names), strict: true);
    }
}
