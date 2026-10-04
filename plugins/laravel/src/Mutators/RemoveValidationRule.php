<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use NightWorksIO\MutationGateLaravel\Rules;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Scalar\String_;

/**
 * One rule is dropped from the rules `validate()` or `Validator::make()` is given.
 *
 * A field's list of rules loses one item, and a field's `'a|b'` string loses its
 * first rule, since a node takes one change.
 */
final readonly class RemoveValidationRule implements Mutator
{
    public function name(): MutatorName
    {
        return LaravelSet::mutator('RemoveValidationRule');
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
        return NodeClasses::of(ArrayItem::class, String_::class);
    }

    public function mutate(Node $node): Node|Removal|Unchanged
    {
        return match (true) {
            $node instanceof ArrayItem && Rules::isRuleOfAField($node) => Removal::item(),
            $node instanceof String_ && Rules::isRulesOfAField($node) => Rules::withoutTheFirst($node),
            default => Unchanged::node(),
        };
    }

    public function hint(): Hint
    {
        return Hint::that('No test sends input this rule alone refuses.');
    }
}
