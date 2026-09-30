<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Logical;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;

/** `or` becomes `and`. */
final readonly class LogicalOrToLogicalAnd implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('LogicalOrToLogicalAnd');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Logical;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(LogicalOr::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof LogicalOr
            ? new LogicalAnd($node->left, $node->right, $node->getAttributes())
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
