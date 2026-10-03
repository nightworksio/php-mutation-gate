<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use Infection\Mutator\Definition;
use Infection\Mutator\MutatorCategory;
use NightWorksIO\MutationGate\Mutator\Engine\Offered;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Node;

/**
 * What a bridge the gate writes for Infection does in Infection's process
 * (ADR-0021): it offers its mutator the nodes the mutator handles, yields
 * its one change, put in place as the testing kit says Infection does, and
 * describes it by its name and its hint.
 */
final readonly class Bridged
{
    /** How Infection describes the mutator: its name, and its own hint where it has one. */
    public static function definition(Mutator $mutator): Definition
    {
        $hint = $mutator->hint();

        return new Definition(
            $mutator->name()->value(),
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            $hint instanceof Hint ? $hint->sentence() : null,
            '',
        );
    }

    public static function canMutate(Mutator $mutator, Node $node): bool
    {
        return $mutator->handles()->has($node);
    }

    /**
     * What takes the node's place for the mutator's change to it, as
     * Infection puts it in place; nothing where it leaves the node alone.
     *
     * @return list<Node|int>
     */
    public static function mutate(Mutator $mutator, Node $node): array
    {
        $change = $mutator->mutate($node);

        return $change instanceof Unchanged ? [] : [Offered::InClassMethods->replacing($node, $change)];
    }
}
