<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Casting;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\Cast\Int_;

/** `(int) $a` becomes `$a`. */
final readonly class RemoveIntegerCast implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('RemoveIntegerCast');
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
        return NodeClasses::of(Int_::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof Int_
            ? $node->expr
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
