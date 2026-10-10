<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Engine\Handlers;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Tests\Fakes\MutatorFake;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Function_;

it('finds the mutators that handle a class by it, a class it extends or an interface it implements, each once, by place', function (): void {
    $handlers = Handlers::of(
        new MutatorFake('Calls', NodeClasses::of(FuncCall::class)),
        new MutatorFake('Binary', NodeClasses::of(BinaryOp::class)),
        new MutatorFake('Plus', NodeClasses::of(Plus::class)),
        new MutatorFake('Twice', NodeClasses::of(BinaryOp::class, Plus::class)),
        new MutatorFake('Functions', NodeClasses::of(FunctionLike::class)),
    );
    $names = static fn(Node $node): array => array_map(
        static fn(Mutator $mutator): string => $mutator->name()->own(),
        iterator_to_array($handlers->handling($node), preserve_keys: true),
    );

    $a = new Variable('a');
    $b = new Variable('b');

    expect($names(new Plus($a, $b)))->toBe([1 => 'Binary', 2 => 'Plus', 3 => 'Twice'])
        ->and($names(new Minus($a, $b)))->toBe([1 => 'Binary', 3 => 'Twice'])
        ->and($names(new Function_('f')))->toBe([4 => 'Functions'])
        ->and($names(new FuncCall(new Name('f'))))->toBe([0 => 'Calls'])
        ->and(iterator_to_array(Handlers::of()->handling(new Plus($a, $b)), preserve_keys: true))->toBe([]);
});
