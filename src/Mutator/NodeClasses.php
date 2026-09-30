<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use function array_any;
use function array_unique;
use function array_values;

use ArrayIterator;
use IteratorAggregate;
use PhpParser\Node;
use Traversable;

/**
 * The node classes a mutator looks at. A runner offers it no other node, and
 * skips it in a file that holds none of them, so a narrow list keeps a run
 * fast.
 *
 * @implements IteratorAggregate<int, class-string<Node>>
 */
final readonly class NodeClasses implements IteratorAggregate
{
    /** @param list<class-string<Node>> $classes */
    private function __construct(private array $classes)
    {
    }

    /** @param class-string<Node> ...$classes */
    public static function of(string ...$classes): self
    {
        return new self(array_values(array_unique($classes)));
    }

    /** Whether a node is of one of these classes. */
    public function has(Node $node): bool
    {
        return array_any($this->classes, static fn(string $class): bool => $node instanceof $class);
    }

    /** @return Traversable<int, class-string<Node>> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->classes);
    }
}
