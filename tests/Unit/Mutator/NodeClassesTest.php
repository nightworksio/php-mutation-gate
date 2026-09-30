<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\NodeClasses;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Int_;

it('holds each class once, in the order given', function (): void {
    expect(iterator_to_array(NodeClasses::of(Plus::class, Minus::class, Plus::class), preserve_keys: false))->toBe([Plus::class, Minus::class]);
});

it('has a node of one of its classes, a parent class among them', function (): void {
    $classes = NodeClasses::of(BinaryOp::class);

    expect($classes->has(new Plus(new Variable('a'), new Int_(1))))->toBeTrue()
        ->and($classes->has(new Variable('a')))->toBeFalse();
});
