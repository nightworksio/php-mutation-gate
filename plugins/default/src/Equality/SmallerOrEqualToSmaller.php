<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Equality;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;

/** `<=` becomes `<`. */
final readonly class SmallerOrEqualToSmaller implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('SmallerOrEqualToSmaller');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Boundary;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(SmallerOrEqual::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof SmallerOrEqual
            ? new Smaller($node->left, $node->right, $node->getAttributes())
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
