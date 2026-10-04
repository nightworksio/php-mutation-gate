<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * What a function's body declares, outermost first (see BodiesOut): its
 * static variables, the functions and classes it declares, anonymous ones
 * among them without the arguments they are made with, which run as the
 * body does, and its closures. What one of them holds is its own, so the
 * search goes no deeper into it.
 */
final class Declared extends NodeVisitorAbstract
{
    /** @var list<Expr|Stmt> */
    private array $declarations = [];

    public function enterNode(Node $node): Node|int
    {
        $declared = match (true) {
            $node instanceof Static_, $node instanceof Function_, $node instanceof ClassLike => $node,
            $node instanceof Closure, $node instanceof ArrowFunction => $node,
            default => false,
        };

        if ($declared === false) {
            return $node;
        }

        $this->declarations[] = $declared;

        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    /** @return list<Expr|Stmt> */
    public function declarations(): array
    {
        return $this->declarations;
    }
}
