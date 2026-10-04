<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Contract\Runner\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;

/**
 * The registered mutator the runner contract runs on a class's declaration:
 * `final` is dropped. The declaration sits outside every method, as a
 * property's default, a class's attribute and a class constant do, so Pest
 * offers it and Infection never does (ADR-0021).
 */
final readonly class RemoveFinal implements Mutator
{
    public function name(): MutatorName
    {
        return MutatorName::of('contract', 'RemoveFinal');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::None;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Class_::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        if (! $node instanceof Class_ || ! $node->isFinal()) {
            return Unchanged::node();
        }

        $open = clone $node;
        $open->flags &= ~Modifiers::FINAL;

        return $open;
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
