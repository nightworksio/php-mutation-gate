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
use NightWorksIO\MutationGateDefault\Argument;
use NightWorksIO\MutationGateDefault\Calls;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;

/** `str_ireplace(…)` becomes its third argument. */
final readonly class UnwrapStrIreplace implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('UnwrapStrIreplace');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Unwrap;
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
        return Calls::unwrapped($node, 'str_ireplace', Argument::Third);
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
