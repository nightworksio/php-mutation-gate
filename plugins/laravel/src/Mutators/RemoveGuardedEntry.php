<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Ancestors;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\PropertyItem;

/**
 * One entry is dropped from a model's `$guarded`.
 *
 * That widens mass assignment, and only a test that posts the guarded field
 * notices. Infection never offers a property's default, so only Pest makes these
 * mutants.
 */
final readonly class RemoveGuardedEntry implements Mutator
{
    private const string GUARDED = 'guarded';

    public function name(): MutatorName
    {
        return LaravelSet::mutator('RemoveGuardedEntry');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Collection;
    }

    public function tags(): Tags
    {
        return Tags::of(Tag::security());
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(ArrayItem::class);
    }

    public function mutate(Node $node): Removal|Unchanged
    {
        $list = $node instanceof ArrayItem ? Ancestors::parent($node) : false;
        $property = $list instanceof Array_ ? Ancestors::parent($list) : false;

        return $property instanceof PropertyItem && $property->name->toString() === self::GUARDED
            ? Removal::item()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test posts this guarded field and checks that it is not saved.');
    }
}
