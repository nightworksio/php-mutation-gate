<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\ControlStructures;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Ternary;

/** A ternary's condition is negated, and a short ternary's condition becomes its middle. */
final readonly class TernaryNegated implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('TernaryNegated');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Condition;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Ternary::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        if (! $node instanceof Ternary) {
            return Unchanged::node();
        }

        $negated = clone $node;
        $negated->if = $node->if ?? $node->cond;
        $negated->cond = new BooleanNot($node->cond);

        return $negated;
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
