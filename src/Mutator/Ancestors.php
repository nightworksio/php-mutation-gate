<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use PhpParser\Node;

/** The nodes around a node a mutator is offered, by the parent each runner sets on it (see Mutator::PARENT). */
final readonly class Ancestors
{
    /**
     * The nearest node of a class that holds this one, or none.
     *
     * @template T of Node
     *
     * @param  class-string<T> $class
     * @return T|false
     */
    public static function nearest(Node $node, string $class): Node|false
    {
        $at = $node->getAttribute(Mutator::PARENT);

        while ($at instanceof Node && ! $at instanceof $class) {
            $at = $at->getAttribute(Mutator::PARENT);
        }

        return $at instanceof $class ? $at : false;
    }

    /** The node that holds this one directly, or none. */
    public static function parent(Node $node): Node|false
    {
        $parent = $node->getAttribute(Mutator::PARENT);

        return $parent instanceof Node ? $parent : false;
    }
}
