<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function array_any;

use NightWorksIO\MutationGate\Mutator\Mutator;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Attribute;
use PhpParser\Node\Const_;
use PhpParser\Node\DeclareItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\ShellExec;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Unset_;

/**
 * Whether an expression is read where a schema's ternary can stand in its
 * place (ADR-0091): not a place written to, passed by reference or listed,
 * and not inside a constant expression, a default, an attribute, a
 * declaration or a string's interpolation, up to the statement or the
 * function body it is in.
 *
 * @internal the engine's own
 */
final readonly class Readers
{
    /** The expressions that name a place, which a ternary cannot stand for. */
    private const array PLACES = [
        Variable::class,
        ArrayDimFetch::class,
        PropertyFetch::class,
        NullsafePropertyFetch::class,
        StaticPropertyFetch::class,
    ];

    /** What no ternary may stand inside: constant expressions, defaults and declarations. */
    private const array CONSTANT = [
        Const_::class,
        PropertyItem::class,
        Param::class,
        StaticVar::class,
        Attribute::class,
        DeclareItem::class,
        EnumCase::class,
        InterpolatedString::class,
        ShellExec::class,
        Isset_::class,
        Unset_::class,
        Global_::class,
    ];

    /** The values PHP passes by reference with a notice, where their parameter takes one. */
    private const array REFERENCED = [
        FuncCall::class,
        MethodCall::class,
        NullsafeMethodCall::class,
        StaticCall::class,
        New_::class,
        Assign::class,
        AssignOp::class,
        PreInc::class,
        PreDec::class,
        PostInc::class,
        PostDec::class,
    ];

    /** Whether a ternary can stand in the expression's place. */
    public static function admit(Expr $expression): bool
    {
        if (self::isPlace($expression)) {
            return false;
        }

        $child = $expression;
        $parent = $expression->getAttribute(Mutator::PARENT);

        while ($parent instanceof Node && ! self::refuses($parent, $child)) {
            if ($parent instanceof Stmt || $parent instanceof FunctionLike) {
                return true;
            }

            $child = $parent;
            $parent = $parent->getAttribute(Mutator::PARENT);
        }

        return ! $parent instanceof Node;
    }

    private static function isPlace(Expr $expression): bool
    {
        return array_any(self::PLACES, static fn(string $place): bool => $expression instanceof $place);
    }

    /**
     * Whether an argument's value is one PHP passes by reference, with a
     * notice, where its parameter takes one: a call's result, a new object or
     * an assignment's, which a ternary in its place could not be.
     */
    private static function mayBeReferenced(Node $value): bool
    {
        return array_any(self::REFERENCED, static fn(string $kind): bool => $value instanceof $kind);
    }

    /** Whether a parent refuses a ternary in its child's place. */
    private static function refuses(Node $parent, Node $child): bool
    {
        return array_any(self::CONSTANT, static fn(string $kind): bool => $parent instanceof $kind)
            || self::writes($parent, $child)
            || ($parent instanceof Arg && self::mayBeReferenced($child));
    }

    /** Whether a parent writes to its child, or takes a reference to it. */
    private static function writes(Node $parent, Node $child): bool
    {
        return (($parent instanceof Assign || $parent instanceof AssignOp) && self::isAt($parent->var, $child))
            || $parent instanceof AssignRef
            || ($parent instanceof ArrayItem && $parent->byRef)
            || ($parent instanceof Foreach_ && (! self::isAt($parent->expr, $child) || $parent->byRef));
    }

    /**
     * Whether a parent's child is this node, as the parser read it, by where
     * it starts, since no two children of one node start at one place: one
     * the parent holds may have been copied, or replaced by a mutant, since.
     */
    private static function isAt(Node $held, Node $child): bool
    {
        return $held->getStartFilePos() === $child->getStartFilePos();
    }
}
