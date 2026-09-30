<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;

/** Turns `+` into `-`, keeping the attributes of the node it replaces, `origNode` among them. */
final readonly class PlusToMinus implements Mutator
{
    public function name(): MutatorName
    {
        return MutatorName::of('acme', 'PlusToMinus');
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
        return NodeClasses::of(Plus::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof Plus ? new Minus($node->left, $node->right, $node->getAttributes()) : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
