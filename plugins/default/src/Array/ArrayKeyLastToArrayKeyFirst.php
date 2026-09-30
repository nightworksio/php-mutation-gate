<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Array;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Calls;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;

/** `array_key_last(…)` becomes `array_key_first(…)`. */
final readonly class ArrayKeyLastToArrayKeyFirst implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('ArrayKeyLastToArrayKeyFirst');
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
        return NodeClasses::of(FuncCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return Calls::renamed($node, 'array_key_last', 'array_key_first');
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
