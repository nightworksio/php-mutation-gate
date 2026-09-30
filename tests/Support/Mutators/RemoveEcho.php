<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use PhpParser\Node;
use PhpParser\Node\Stmt\Echo_;

/** Removes an `echo` statement. */
final readonly class RemoveEcho implements Mutator
{
    public function name(): MutatorName
    {
        return MutatorName::of('acme', 'RemoveEcho');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::RemovedCall;
    }

    public function tags(): Tags
    {
        return Tags::of(Tag::named('output'));
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Echo_::class);
    }

    public function mutate(Node $node): Removal
    {
        return Removal::statement();
    }

    public function hint(): Hint
    {
        return Hint::that('No test checks what is printed.');
    }
}
