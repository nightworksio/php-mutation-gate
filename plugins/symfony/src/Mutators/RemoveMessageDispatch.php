<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSymfony\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Calls;
use NightWorksIO\MutationGateSymfony\Receivers;
use NightWorksIO\MutationGateSymfony\SymfonySet;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\Expression;

/**
 * `->dispatch(…)` on a message bus is removed.
 *
 * A message bus is a parameter of the function around the call, or a property
 * of `$this`, declared a `MessageBusInterface`.
 */
final readonly class RemoveMessageDispatch implements Mutator
{
    public function name(): MutatorName
    {
        return SymfonySet::mutator('RemoveMessageDispatch');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::RemovedCall;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Expression::class);
    }

    public function mutate(Node $node): Removal|Unchanged
    {
        return $node instanceof Expression
            && $node->expr instanceof MethodCall
            && Calls::named($node->expr->name, 'dispatch')
            && Receivers::isMessageBus($node->expr->var)
            ? Removal::statement()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test checks that this message is dispatched.');
    }
}
