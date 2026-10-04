<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Logical;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Literals;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Calls;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;

/**
 * `true` becomes `false`, but for an argument of `in_array()` or `array_search()`,
 * whose strict flag it would only loosen.
 */
final readonly class TrueToFalse implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('TrueToFalse');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Literal;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(ConstFetch::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof ConstFetch && Literals::isTrue($node) && ! Calls::isStrictFlag($node)
            ? Literals::false()
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
