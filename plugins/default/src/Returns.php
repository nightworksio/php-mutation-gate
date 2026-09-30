<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault;

use function array_map;
use function in_array;

use NightWorksIO\MutationGate\Mutator\Mutator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\UnionType;

/**
 * What a `return` stands in: the method it returns early from, and the type
 * the function it ends declares.
 */
final readonly class Returns
{
    private const string ARRAY = 'array';

    /**
     * Whether another `return` follows this one in its method, reading the
     * method's statements and the statements they hold in the order they
     * stand, but not those of an `elseif`, an `else`, a `case`, a `catch`, a
     * `finally` or a closure.
     */
    public static function isEarly(Return_ $return): bool
    {
        $method = $return->getAttribute(Mutator::PARENT);

        while ($method instanceof Node && ! $method instanceof ClassMethod) {
            $method = $method->getAttribute(Mutator::PARENT);
        }

        $after = false;

        foreach ($method instanceof ClassMethod ? self::flattened($method) : [] as $statement) {
            if ($after && $statement instanceof Return_) {
                return true;
            }

            $after = $after || $statement === $return;
        }

        return false;
    }

    /**
     * Whether a `return` directly in a function's or method's body may
     * return `null` in its place: it returns something else, and the function
     * declares no return type, a nullable one, or a union with `null`.
     */
    public static function mayReturnNull(Return_ $return): bool
    {
        $function = $return->getAttribute(Mutator::PARENT);

        if (! $function instanceof Function_ && ! $function instanceof ClassMethod) {
            return false;
        }

        $type = $function->returnType;

        return (!$return->expr instanceof Expr || !Literals::isNull($return->expr))
            && (! $type instanceof Node || $type instanceof NullableType || self::unites($type, Literals::NULL));
    }

    /**
     * Whether a `return` directly in a function's or method's body may
     * return `[]` in its place: it returns something else, and the function
     * declares `array`, or a union with it.
     */
    public static function mayReturnAnArray(Return_ $return): bool
    {
        $function = $return->getAttribute(Mutator::PARENT);

        if (! $function instanceof Function_ && ! $function instanceof ClassMethod) {
            return false;
        }

        $type = $function->returnType;

        return (!$return->expr instanceof Expr || !Literals::isEmptyArray($return->expr))
            && $type instanceof Node
            && (($type instanceof Identifier && $type->name === self::ARRAY) || self::unites($type, self::ARRAY));
    }

    /** Whether a type is a union with a type of this name, as written. */
    private static function unites(Node $type, string $name): bool
    {
        return $type instanceof UnionType && in_array(
            $name,
            array_map(static fn(Node $one): string => $one instanceof Identifier ? $one->name : '', $type->types),
            strict: true,
        );
    }

    /**
     * The statements a statement holds, each followed by those it holds.
     *
     * @return list<Stmt>
     */
    private static function flattened(Stmt $statement): array
    {
        $flat = [];

        foreach (self::held($statement) as $held) {
            $flat = [...$flat, $held, ...self::flattened($held)];
        }

        return $flat;
    }

    /** @return array<Stmt> */
    private static function held(Stmt $statement): array
    {
        return match (true) {
            $statement instanceof ClassMethod, $statement instanceof Declare_ => $statement->stmts ?? [],
            $statement instanceof Block, $statement instanceof ClassLike, $statement instanceof Do_,
            $statement instanceof For_, $statement instanceof Foreach_, $statement instanceof Function_,
            $statement instanceof If_, $statement instanceof TryCatch, $statement instanceof While_
                => $statement->stmts,
            default => [],
        };
    }
}
