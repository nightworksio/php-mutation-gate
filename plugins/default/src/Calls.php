<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault;

use function array_key_exists;
use function in_array;

use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;

/**
 * Calls of a function by its bare name, as written: `array_map(…)` and
 * `\array_map(…)`, never `Foo\array_map(…)` nor a call through a variable.
 */
final readonly class Calls
{
    /** The functions whose `true` argument makes them strict, which `false` would only loosen. */
    private const array STRICT = ['in_array', 'array_search'];

    /** The parameter of the arrow function that stands in for a first-class callable. */
    private const string VALUE = 'value';

    /**
     * The call's argument at a position in the call's place. A first-class
     * callable, `trim(...)`, becomes `fn($value) => $value` in place of its
     * first argument.
     */
    public static function unwrapped(Node $node, string $function, Argument $argument): Node|Unchanged
    {
        if (! self::calls($node, $function)) {
            return Unchanged::node();
        }

        $arguments = $node->getRawArgs();

        return match (true) {
            $node->isFirstClassCallable() && $argument === Argument::First => self::identity(),
            array_key_exists($argument->value, $arguments) && $arguments[$argument->value] instanceof Arg
                => $arguments[$argument->value]->value,
            default => Unchanged::node(),
        };
    }

    /** The call, of another function by its bare name. */
    public static function renamed(Node $node, string $from, string $to): Node|Unchanged
    {
        if (! self::calls($node, $from)) {
            return Unchanged::node();
        }

        $renamed = clone $node;
        $renamed->name = new Name($to);

        return $renamed;
    }

    /** Whether a node is an argument of `in_array()` or `array_search()`, as their strict flag is. */
    public static function isStrictFlag(Node $node): bool
    {
        $argument = $node->getAttribute(Mutator::PARENT);
        $call = $argument instanceof Node ? $argument->getAttribute(Mutator::PARENT) : $argument;

        return $call instanceof FuncCall
            && $call->name instanceof Name
            && in_array($call->name->toCodeString(), self::STRICT, strict: true);
    }

    /** @phpstan-assert-if-true FuncCall $node */
    private static function calls(Node $node, string $function): bool
    {
        return $node instanceof FuncCall && $node->name instanceof Name && $node->name->getParts() === [$function];
    }

    private static function identity(): ArrowFunction
    {
        return new ArrowFunction([
            'params' => [new Param(new Variable(self::VALUE))],
            'expr' => new Variable(self::VALUE),
        ]);
    }
}
