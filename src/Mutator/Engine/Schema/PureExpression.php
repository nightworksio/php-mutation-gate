<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function array_all;
use function array_any;
use function in_array;
use function is_string;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * An expression that runs no code but PHP's own where what it reads holds no
 * object (ADR-0117): a number, a string with nothing in it, a constant, a
 * class constant by a name written out, a variable by a name written out
 * other than `$this`, and operators of such expressions. A split stream evaluates its mutated
 * code beside its original, where the variables it reads hold no object, to
 * see whether this execution changes the value, with nothing to undo.
 * Concatenation, a cast to string or object, an interpolated string, an array
 * or property read, any call, an assignment and a magic constant can each
 * run code whatever they read, so none is pure.
 *
 * @internal the engine's own
 */
final readonly class PureExpression
{
    /** The leaves that run nothing. */
    private const array LEAVES = [
        Scalar\Int_::class,
        Scalar\Float_::class,
        Scalar\String_::class,
        Expr\ConstFetch::class,
        Name::class,
        Identifier::class,
    ];

    /** The variables a closure of the expression cannot read as the expression does. */
    private const array NOT_READ = ['this', 'GLOBALS'];

    /** The operators that run nothing of their own on what they take. */
    private const array OPERATORS = [
        Expr\BooleanNot::class,
        Expr\UnaryMinus::class,
        Expr\UnaryPlus::class,
        Expr\BitwiseNot::class,
        Expr\Ternary::class,
        Expr\Instanceof_::class,
        Cast\Int_::class,
        Cast\Double::class,
        Cast\Bool_::class,
    ];

    public static function is(Node $node): bool
    {
        return match (true) {
            array_any(self::LEAVES, static fn(string $leaf): bool => $node instanceof $leaf) => true,
            $node instanceof Expr\Variable => is_string($node->name) && ! in_array($node->name, self::NOT_READ, strict: true),
            $node instanceof Expr\ClassConstFetch => $node->class instanceof Name && $node->name instanceof Identifier,
            $node instanceof BinaryOp => ! $node instanceof BinaryOp\Concat && self::all($node->left, $node->right),
            array_any(self::OPERATORS, static fn(string $operator): bool => $node instanceof $operator)
                => self::all(...$node->getSubNodeNames() === [] ? [] : self::children($node)),
            default => false,
        };
    }

    /** @return list<Node> */
    private static function children(Node $node): array
    {
        $children = [];

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};

            if ($child instanceof Node) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private static function all(Node ...$nodes): bool
    {
        return array_all($nodes, self::is(...));
    }
}
