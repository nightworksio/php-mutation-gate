<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSymfony\Mutators;

use function array_filter;
use function array_values;
use function count;
use function in_array;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\ResolvedName;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\SymfonySet;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;

/**
 * `#[IsGranted(…)]` is removed.
 *
 * On a method, both runners make the mutant. Infection never offers an
 * attribute of a class, so only Pest removes one there.
 */
final readonly class RemoveIsGrantedAttribute implements Mutator
{
    /** The attribute's class: Symfony's own, and the one SensioFrameworkExtraBundle had. */
    private const array IS_GRANTED = [
        'Symfony\\Component\\Security\\Http\\Attribute\\IsGranted',
        'Sensio\\Bundle\\FrameworkExtraBundle\\Configuration\\IsGranted',
    ];

    public function name(): MutatorName
    {
        return SymfonySet::mutator('RemoveIsGrantedAttribute');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::RemovedCall;
    }

    public function tags(): Tags
    {
        return Tags::of(Tag::security());
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(AttributeGroup::class);
    }

    public function mutate(Node $node): Node|Removal|Unchanged
    {
        if (! $node instanceof AttributeGroup) {
            return Unchanged::node();
        }

        $kept = array_values(array_filter(
            $node->attrs,
            static fn(Attribute $attribute): bool
                => ! in_array(ResolvedName::of($attribute->name), self::IS_GRANTED, strict: true),
        ));

        return match (count($kept)) {
            count($node->attrs) => Unchanged::node(),
            0 => Removal::item(),
            default => new AttributeGroup($kept),
        };
    }

    public function hint(): Hint
    {
        return Hint::that('No test calls this without the grant and checks that it is refused.');
    }
}
