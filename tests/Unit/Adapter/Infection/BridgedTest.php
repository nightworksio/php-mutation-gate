<?php

declare(strict_types=1);

use Infection\Mutator\MutatorCategory;
use NightWorksIO\MutationGate\Adapter\Infection\Bridged;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveItem;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeVisitor;

it('describes the mutator by its name, and by its own hint where it has one', function (): void {
    $hinted = Bridged::definition(new RemoveEcho());
    $unhinted = Bridged::definition(new PlusToMinus());

    expect([$hinted->getDescription(), $hinted->getRemedies(), $hinted->getCategory()])
        ->toBe(['acme/RemoveEcho', 'No test checks what is printed.', MutatorCategory::ORTHOGONAL_REPLACEMENT])
        ->and($unhinted->getRemedies())->toBeNull();
});

it('offers its mutator every node it handles', function (): void {
    expect(Bridged::canMutate(new PlusToMinus(), new Plus(new Variable('a'), new Variable('b'))))->toBeTrue()
        ->and(Bridged::canMutate(new PlusToMinus(), new Variable('a')))->toBeFalse();
});

it('yields the one change, put in place as Infection puts it, and nothing for a node left alone', function (): void {
    $plus = new Plus(new Variable('a'), new Variable('b'), ['origNode' => new Plus(new Variable('a'), new Variable('b'))]);
    $changed = Bridged::mutate(new PlusToMinus(), $plus);

    expect($changed)->toHaveCount(1)
        ->and($changed[0] ?? null)->toBeInstanceOf(Minus::class)
        ->and(($changed[0] ?? null) instanceof Minus ? $changed[0]->getAttributes() : [])->not->toHaveKey('origNode')
        ->and(Bridged::mutate(new PlusToMinus(), new Minus(new Variable('a'), new Variable('b'))))->toBe([])
        ->and(Bridged::mutate(new RemoveEcho(), new Echo_([])))->toEqual([new Nop()])
        ->and(Bridged::mutate(new RemoveItem(), new ArrayItem(new Variable('a'))))->toBe([NodeVisitor::REMOVE_NODE]);
});
