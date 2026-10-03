<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_diff_key;

use NightWorksIO\MutationGate\Mutator\Removal;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeVisitor;

/**
 * Which nodes a mutator is offered, and how a removed statement goes: as a
 * runner offers them, and as the bridge the gate writes for it puts each
 * change in place.
 *
 * @internal the engine's, the testing kit's and the bridges' own
 */
enum Offered
{
    /** Every node, and a removed statement is gone: as Pest offers, and as the gate's own engine does. */
    case Everywhere;

    /** Only nodes in a class method or on its signature, and a removed statement is emptied: as Infection offers. */
    case InClassMethods;

    /** The attribute php-parser's cloning keeps a node's original in. */
    private const string ORIGINAL = 'origNode';

    /**
     * What takes a node's place for a mutator's change to it. A removal
     * empties a statement where only class methods are offered, and is gone
     * otherwise. A node built from the original's attributes loses
     * `origNode`, which names the original's class and would make
     * php-parser's format-preserving printer refuse it.
     */
    public function replacing(Node $node, Node|Removal $change): Node|int
    {
        if ($change instanceof Node) {
            $change->setAttributes(array_diff_key($change->getAttributes(), [self::ORIGINAL => true]));

            return $change;
        }

        return $this === self::InClassMethods && $node instanceof Stmt ? new Nop() : NodeVisitor::REMOVE_NODE;
    }
}
