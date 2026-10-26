<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use function array_all;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BitwiseNot;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

/**
 * The constant expressions a program writes, outermost first (see
 * Declarations): each expression made only of literals, constants and
 * what opcache can work out from them as it compiles, printed, and none
 * inside one already found. Opcache folds them into the values it dumps,
 * and prints some of those only in part.
 */
final class Constants extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $printed = [];

    public function __construct(private readonly Standard $printer)
    {
    }

    public function enterNode(Node $node): Node|int
    {
        if (! $node instanceof Expr || ! self::isConstant($node)) {
            return $node;
        }

        $this->printed[] = $this->printer->prettyPrintExpr($node);

        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    /** @return list<string> */
    public function printed(): array
    {
        return $this->printed;
    }

    private static function isConstant(Node $node): bool
    {
        return match (true) {
            $node instanceof Int_, $node instanceof Float_, $node instanceof String_,
            $node instanceof MagicConst, $node instanceof ConstFetch => true,
            $node instanceof ClassConstFetch => $node->class instanceof Name && $node->name instanceof Identifier,
            $node instanceof Array_ => self::allConstant($node->items),
            $node instanceof ArrayItem => self::allConstant([$node->key, $node->value]),
            $node instanceof FuncCall => $node->name instanceof Name && self::allConstant($node->args),
            $node instanceof Arg => self::isConstant($node->value),
            default => self::isConstantOperation($node),
        };
    }

    private static function isConstantOperation(Node $node): bool
    {
        return match (true) {
            $node instanceof BinaryOp => self::isConstant($node->left) && self::isConstant($node->right),
            $node instanceof UnaryMinus, $node instanceof UnaryPlus, $node instanceof BitwiseNot,
            $node instanceof BooleanNot, $node instanceof Cast => self::isConstant($node->expr),
            $node instanceof Ternary => self::allConstant([$node->cond, $node->if, $node->else]),
            $node instanceof ArrayDimFetch => self::allConstant([$node->var, $node->dim]),
            default => false,
        };
    }

    /**
     * Whether every part given is constant, a part left out, as a key or a
     * ternary's middle, among them.
     *
     * @param array<Node|null> $nodes
     */
    private static function allConstant(array $nodes): bool
    {
        return array_all($nodes, fn(?Node $node): bool => !$node instanceof Node || self::isConstant($node));
    }
}
