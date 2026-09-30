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
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Continue_;

/** `break` becomes `continue`. */
final readonly class BreakToContinue implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('BreakToContinue');
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
        return NodeClasses::of(Break_::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof Break_
            ? new Continue_($node->num, $node->getAttributes())
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
