<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ParserNodes;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

it('names each node class a mutator handles, and each subclass of one, the ones it names first', function (): void {
    $nodes = ParserNodes::in(Tree::at('vendor'));

    expect($nodes->handled(NodeClasses::of(Plus::class)))->toBe([Plus::class])
        ->and($nodes->handled(NodeClasses::of(BinaryOp::class)))->toContain(BinaryOp::class, Minus::class, Plus::class)
        ->and($nodes->handled(NodeClasses::of(BinaryOp::class))[0])->toBe(BinaryOp::class)
        ->and($nodes->handled(NodeClasses::of(FunctionLike::class)))->toContain(ClassMethod::class, Function_::class);
});

it('names only the classes a mutator handles where the vendor directory holds no php-parser', function (): void {
    expect(ParserNodes::in('/nowhere')->handled(NodeClasses::of(BinaryOp::class)))->toBe([BinaryOp::class]);
});
