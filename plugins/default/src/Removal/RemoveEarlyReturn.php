<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Removal;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use NightWorksIO\MutationGateDefault\Returns;
use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;

/** A `return` another `return` of its method follows is removed. */
final readonly class RemoveEarlyReturn implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('RemoveEarlyReturn');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::ReturnValue;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Return_::class);
    }

    public function mutate(Node $node): Removal|Unchanged
    {
        return $node instanceof Return_ && Returns::isEarly($node)
            ? Removal::statement()
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
