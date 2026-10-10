<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use PhpParser\Node;

/** A mutator that looks at the node classes it is given, and hands back each node it is offered as its change. */
final readonly class MutatorFake implements Mutator
{
    public function __construct(private string $name, private NodeClasses $classes)
    {
    }

    public function name(): MutatorName
    {
        return MutatorName::of('fake', $this->name);
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
        return $this->classes;
    }

    public function mutate(Node $node): Node
    {
        return $node;
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
