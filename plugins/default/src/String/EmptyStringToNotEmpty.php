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
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Scalar\String_;

/** `''` becomes a string that is not empty. */
final readonly class EmptyStringToNotEmpty implements Mutator
{
    /** What an empty string becomes. */
    private const string NOT_EMPTY = 'mutation-gate was here';

    public function name(): MutatorName
    {
        return DefaultSet::mutator('EmptyStringToNotEmpty');
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
        return NodeClasses::of(String_::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof String_ && $node->value === ''
            ? new String_(self::NOT_EMPTY, $node->getAttributes())
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
