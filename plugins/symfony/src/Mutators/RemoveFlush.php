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
 * `->flush()` on an entity manager is removed.
 *
 * An entity manager is told by its name: `$em`, `$manager`, a name that ends in
 * `EntityManager` or `ObjectManager`, a property of `$this` so named, or what
 * `getManager()` hands out.
 */
final readonly class RemoveFlush implements Mutator
{
    public function name(): MutatorName
    {
        return SymfonySet::mutator('RemoveFlush');
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
            && Calls::named($node->expr->name, 'flush')
            && Receivers::isEntityManager($node->expr->var)
            ? Removal::statement()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test reads back what this flush saves.');
    }
}
