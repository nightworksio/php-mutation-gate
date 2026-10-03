<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Contract\Runner\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Mul;
use PhpParser\Node\Expr\BinaryOp\Plus;

/**
 * The registered mutator the runner contract runs through each runner's
 * bridge: `+` into `*`. It handles every binary operation, an abstract node
 * class, so a runner must offer it the subclasses' nodes.
 */
final readonly class PlusToTimes implements Mutator
{
    public function name(): MutatorName
    {
        return MutatorName::of('contract', 'PlusToTimes');
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
        return NodeClasses::of(BinaryOp::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof Plus ? new Mul($node->left, $node->right, $node->getAttributes()) : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
