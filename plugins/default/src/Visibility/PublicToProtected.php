<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Visibility;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;

/** A public method becomes protected, but for a magic method and a method of an interface or an enum. */
final readonly class PublicToProtected implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('PublicToProtected');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Visibility;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(ClassMethod::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        if (! $node instanceof ClassMethod || ! $this->narrows($node)) {
            return Unchanged::node();
        }

        $narrowed = clone $node;
        $narrowed->flags = ($node->flags & ~Modifiers::PUBLIC) | Modifiers::PROTECTED;

        return $narrowed;
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }

    /** Whether a method is public, not magic, and in a class or trait, where it may be protected. */
    private function narrows(ClassMethod $method): bool
    {
        $owner = $method->getAttribute(Mutator::PARENT);

        return $method->isPublic()
            && ! $method->isMagic()
            && ! $owner instanceof Interface_
            && ! $owner instanceof Enum_;
    }
}
