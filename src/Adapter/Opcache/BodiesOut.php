<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use function array_map;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst\Line;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Takes each function's body out of a program, keeping what the body
 * declares (see Declarations): its static variables, functions and classes
 * as statements and its closures as expressions. A body that is one
 * expression, as an arrow function's or a short property hook's is, keeps
 * them as the statements of a closure in its place.
 * Bodies inside are taken out first, so what a body keeps is already
 * without its own.
 */
final class BodiesOut extends NodeVisitorAbstract
{
    public function enterNode(Node $node): Node
    {
        return $node instanceof Line ? new Int_($node->getStartLine()) : $node;
    }

    public function leaveNode(Node $node): Node
    {
        return match (true) {
            $node instanceof Function_, $node instanceof ClassMethod, $node instanceof Closure
                => $this->stated($node),
            $node instanceof ArrowFunction => $this->expressed($node),
            $node instanceof PropertyHook => $this->hooked($node),
            default => $node,
        };
    }

    private function stated(Function_|ClassMethod|Closure $function): Node
    {
        $function->stmts = $function->stmts === null ? null : $this->statements($function->stmts);

        return $function;
    }

    private function expressed(ArrowFunction $function): Node
    {
        $function->expr = $this->enclosed($function->expr);

        return $function;
    }

    private function hooked(PropertyHook $hook): Node
    {
        $hook->body = match (true) {
            $hook->body instanceof Expr => $this->enclosed($hook->body),
            $hook->body === null => null,
            default => $this->statements($hook->body),
        };

        return $hook;
    }

    /**
     * @param  array<Node> $body
     * @return list<Stmt>
     */
    private function statements(array $body): array
    {
        return array_map(
            static fn(Node $declared): Stmt => $declared instanceof Stmt ? $declared : new Expression($declared),
            $this->declaredIn($body),
        );
    }

    private function enclosed(Expr $body): Closure
    {
        return new Closure(['stmts' => $this->statements([$body])]);
    }

    /**
     * @param  array<Node> $body
     * @return list<Expr|Stmt>
     */
    private function declaredIn(array $body): array
    {
        $found = new Declared();
        new NodeTraverser($found)->traverse($body);

        return $found->declarations();
    }
}
