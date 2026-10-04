<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\ControlStructures;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Literals;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Stmt\Do_;

/** A `do … while` loop's condition becomes `false`. */
final readonly class DoWhileAlwaysFalse implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('DoWhileAlwaysFalse');
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
        return NodeClasses::of(Do_::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        if (! $node instanceof Do_) {
            return Unchanged::node();
        }

        $changed = clone $node;
        $changed->cond = Literals::false();

        return $changed;
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
