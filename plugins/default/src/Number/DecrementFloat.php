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
use PhpParser\Node\Scalar\Float_;

/** A float becomes one less. */
final readonly class DecrementFloat implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('DecrementFloat');
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
        return NodeClasses::of(Float_::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof Float_
            ? Numbers::lessFloat($node)
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
