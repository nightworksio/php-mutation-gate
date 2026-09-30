<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Arithmetic;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;

/** `--$a` becomes `++$a`. */
final readonly class PreDecrementToPreIncrement implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('PreDecrementToPreIncrement');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Arithmetic;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(PreDec::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof PreDec
            ? new PreInc($node->var, $node->getAttributes())
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
