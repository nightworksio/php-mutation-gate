<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Assignment;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\AssignOp\BitwiseAnd;
use PhpParser\Node\Expr\AssignOp\BitwiseXor;

/** `^=` becomes `&=`. */
final readonly class BitwiseXorEqualToBitwiseAndEqual implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('BitwiseXorEqualToBitwiseAndEqual');
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
        return NodeClasses::of(BitwiseXor::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof BitwiseXor
            ? new BitwiseAnd($node->var, $node->expr, $node->getAttributes())
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
