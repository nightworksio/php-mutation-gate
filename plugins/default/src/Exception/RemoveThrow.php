<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Exception;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt\Expression;

/** A statement that only throws is removed. */
final readonly class RemoveThrow implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('RemoveThrow');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Exception;
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
        return $node instanceof Expression && $node->expr instanceof Throw_
            ? Removal::statement()
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
