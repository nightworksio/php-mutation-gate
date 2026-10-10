<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_values;

use ArrayIterator;
use NightWorksIO\MutationGate\Mutator\Mutator;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use Traversable;

/**
 * A tree with one node replaced, or removed from the list it stands in, as a
 * runner puts a change in place: every ancestor of the node is copied to hold
 * the change, so the tree itself, which every other change is made to, is
 * left as it was. Each node knows its parent, in its `parent` attribute.
 *
 * @internal the engine's own
 */
final readonly class Replaced
{
    /**
     * The tree with a replacement in a node's place, the node's ancestors
     * copied to hold it, so the tree itself is left as it was.
     *
     * @return Traversable<int, Node>
     */
    public static function in(Node $node, Node|int $replacement, Node ...$tree): Traversable
    {
        return new ArrayIterator(self::replaced(array_values($tree), $node, $replacement));
    }

    /**
     * @param  list<Node> $tree
     * @return list<Node>
     */
    private static function replaced(array $tree, Node $node, Node|int $replacement): array
    {
        $parent = $node->getAttribute(Mutator::PARENT);

        if (! $parent instanceof Node) {
            return self::swapped($tree, $node, $replacement);
        }

        $copy = clone $parent;
        new NodeTraverser(self::replacing($copy, $node, $replacement))->traverse([$copy]);

        return self::replaced($tree, $parent, $copy);
    }

    /**
     * Top-level statements with a replacement in one's place: a node in its
     * place, or none where the statement is removed.
     *
     * @param  list<Node> $nodes
     * @return list<Node>
     */
    private static function swapped(array $nodes, Node $node, Node|int $replacement): array
    {
        $swapped = [];

        foreach ($nodes as $each) {
            $kept = $each === $node ? $replacement : $each;

            if ($kept instanceof Node) {
                $swapped[] = $kept;
            }
        }

        return $swapped;
    }

    /**
     * A visitor that puts a replacement in the place of one of a node's own
     * subnodes, as the runner would, looking no deeper than them.
     */
    private static function replacing(Node $parent, Node $target, Node|int $replacement): NodeVisitor
    {
        return new class ($parent, $target, $replacement) extends NodeVisitorAbstract {
            public function __construct(
                private readonly Node $parent,
                private readonly Node $target,
                private readonly Node|int $replacement,
            ) {
            }

            public function enterNode(Node $node): Node|int
            {
                return $node === $this->parent ? $node : NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            public function leaveNode(Node $node): Node|int
            {
                return $node === $this->target ? $this->replacement : $node;
            }
        };
    }
}
