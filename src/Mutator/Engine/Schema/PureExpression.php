<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function array_all;
use function array_any;
use function array_filter;
use function in_array;
use function is_string;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BitwiseNot;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\Cast\Bool_;
use PhpParser\Node\Expr\Cast\Double;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

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
        Int_::class,
        Float_::class,
        String_::class,
        ConstFetch::class,
        Name::class,
        Identifier::class,
    ];

    /** The variables a closure of the expression cannot read as the expression does. */
    private const array NOT_READ = ['this', 'GLOBALS'];

    public static function is(Node $node): bool
    {
        return match (true) {
            array_any(self::LEAVES, static fn(string $leaf): bool => $node instanceof $leaf) => true,
            $node instanceof Variable => is_string($node->name)
                && ! in_array($node->name, self::NOT_READ, strict: true),
            $node instanceof ClassConstFetch => $node->class instanceof Name && $node->name instanceof Identifier,
            $node instanceof BinaryOp => ! $node instanceof Concat && self::all($node->left, $node->right),
            $node instanceof Ternary => self::all(...array_filter([$node->cond, $node->if, $node->else])),
            $node instanceof Instanceof_ => self::all($node->expr, $node->class),
            $node instanceof BooleanNot => self::is($node->expr),
            $node instanceof UnaryMinus => self::is($node->expr),
            $node instanceof UnaryPlus => self::is($node->expr),
            $node instanceof BitwiseNot => self::is($node->expr),
            $node instanceof Cast\Int_ => self::is($node->expr),
            $node instanceof Double => self::is($node->expr),
            $node instanceof Bool_ => self::is($node->expr),
            default => false,
        };
    }

    private static function all(Node ...$nodes): bool
    {
        return array_all($nodes, fn(Node $node): bool => self::is($node));
    }
}
