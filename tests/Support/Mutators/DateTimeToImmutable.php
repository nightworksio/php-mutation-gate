<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;

/**
 * Makes `new \DateTime()` a `new \DateTimeImmutable()`, knowing the class by
 * its resolved name whatever the code calls it, and leaves every other `new`.
 */
final readonly class DateTimeToImmutable implements Mutator
{
    private const string RESOLVED = 'resolvedName';

    public function name(): MutatorName
    {
        return MutatorName::of('acme', 'DateTimeToImmutable');
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
        return NodeClasses::of(New_::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        $class = $node instanceof New_ && $node->class instanceof Name
            ? $node->class->getAttribute(self::RESOLVED)
            : null;

        return $node instanceof New_ && $class instanceof Name && $class->toString() === 'DateTime'
            ? new New_(new FullyQualified('DateTimeImmutable'), $node->args, $node->getAttributes())
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
