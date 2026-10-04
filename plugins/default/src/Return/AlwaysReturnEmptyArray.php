<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Return;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Literals;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use NightWorksIO\MutationGateDefault\Returns;
use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;

/** A `return` directly in a function's body returns `[]`, where the function returns an `array`. */
final readonly class AlwaysReturnEmptyArray implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('AlwaysReturnEmptyArray');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::ReturnValue;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Return_::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        if (! $node instanceof Return_ || ! Returns::mayReturnAnArray($node)) {
            return Unchanged::node();
        }

        $empty = clone $node;
        $empty->expr = Literals::emptyArray();

        return $empty;
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
