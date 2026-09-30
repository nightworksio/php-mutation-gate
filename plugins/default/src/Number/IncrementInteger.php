<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Number;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use NightWorksIO\MutationGateDefault\Numbers;
use PhpParser\Node;
use PhpParser\Node\Scalar\Int_;

/** An integer becomes one more, but for a `declare` value and the largest integer. */
final readonly class IncrementInteger implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('IncrementInteger');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Literal;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Int_::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof Int_ && Numbers::canMove($node)
            ? Numbers::moreInteger($node)
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
