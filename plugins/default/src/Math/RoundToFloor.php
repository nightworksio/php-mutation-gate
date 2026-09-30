<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Math;

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

/** `round(…)` becomes `floor(…)`. */
final readonly class RoundToFloor implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('RoundToFloor');
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
        return NodeClasses::of(FuncCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return Calls::renamed($node, 'round', 'floor');
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
