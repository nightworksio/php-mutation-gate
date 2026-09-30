<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\String;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;

/** `$a . $b` becomes `$a`. */
final readonly class ConcatRemoveRight implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('ConcatRemoveRight');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::None;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Concat::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof Concat
            ? $node->left
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
