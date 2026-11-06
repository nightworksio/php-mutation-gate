<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use PhpParser\Node;
use RuntimeException;

use function sprintf;

/** The nodes of code a test parsed, in the order they stand, to pick a site's node from. */
final readonly class Nodes
{
    /** @param list<Node> $nodes */
    public function __construct(private array $nodes)
    {
    }

    /**
     * The nth node of a class, counted from 0 in the order the nodes stand.
     *
     * @template T of Node
     *
     * @param  class-string<T> $class
     * @return T
     */
    public function nth(string $class, int $at = 0): Node
    {
        $found = 0;

        foreach ($this->nodes as $node) {
            if ($node instanceof $class && $found++ === $at) {
                return $node;
            }
        }

        throw new RuntimeException(sprintf('The code holds no node %d of %s.', $at, $class));
    }
}
