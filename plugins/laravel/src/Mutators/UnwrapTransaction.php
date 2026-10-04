<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Calls;
use NightWorksIO\MutationGateLaravel\Facade;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;

/** `DB::transaction($callback)` becomes `$callback()`. */
final readonly class UnwrapTransaction implements Mutator
{
    public function name(): MutatorName
    {
        return LaravelSet::mutator('UnwrapTransaction');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Unwrap;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(StaticCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        $callback = $node instanceof StaticCall && Calls::onFacade($node, Facade::Db, 'transaction')
            ? Calls::argument($node, 0)
            : false;

        return $callback instanceof Node ? new FuncCall($callback) : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test makes the transaction fail and checks that nothing it did was kept.');
    }
}
