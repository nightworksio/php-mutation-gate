<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Ancestors;
use NightWorksIO\MutationGate\Mutator\Mutator;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;

it('finds the nearest node of a class around a node, by the parents a runner sets, and none past the top', function (): void {
    $variable = new Variable('a');
    $expression = new Expression($variable);
    $method = new ClassMethod('add', ['stmts' => [$expression]]);
    $variable->setAttribute(Mutator::PARENT, $expression);
    $expression->setAttribute(Mutator::PARENT, $method);

    expect(Ancestors::nearest($variable, ClassMethod::class))->toBe($method)
        ->and(Ancestors::nearest($variable, Expression::class))->toBe($expression)
        ->and(Ancestors::nearest($variable, Return_::class))->toBeFalse()
        ->and(Ancestors::nearest($method, ClassMethod::class))->toBeFalse()
        ->and(Ancestors::parent($variable))->toBe($expression)
        ->and(Ancestors::parent($method))->toBeFalse();
});
