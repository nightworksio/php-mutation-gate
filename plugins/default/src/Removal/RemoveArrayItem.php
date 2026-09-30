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
use PhpParser\Node;
use PhpParser\Node\ArrayItem;

/** An item of an array is removed. */
final readonly class RemoveArrayItem implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('RemoveArrayItem');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Collection;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(ArrayItem::class);
    }

    public function mutate(Node $node): Removal|Unchanged
    {
        return $node instanceof ArrayItem
            ? Removal::item()
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
