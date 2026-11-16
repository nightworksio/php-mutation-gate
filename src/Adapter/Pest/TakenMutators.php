<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;

use Closure;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Which of some mutators a node of a file can take, asked as a walk of the
 * file leaves each node, as Pest's attempt at a mutant asks: a mutator is
 * asked of every node until it takes one.
 *
 * @internal
 */
final class TakenMutators extends NodeVisitorAbstract
{
    /** @var array<string, true> the mutators some node was taken by, by name */
    private array $taken = [];

    /**
     * @param list<string>                 $left  the mutators no node was taken by yet
     * @param Closure(string, Node): bool $takes whether a mutator takes a node, as Pest asks it
     */
    public function __construct(private array $left, private readonly Closure $takes)
    {
    }

    public function leaveNode(Node $node): null
    {
        $left = [];

        foreach ($this->left as $mutator) {
            ($this->takes)($mutator, $node) ? $this->taken[$mutator] = true : $left[] = $mutator;
        }

        $this->left = $left;

        return null;
    }

    /** Whether some node was taken by this mutator. */
    public function takes(string $mutator): bool
    {
        return array_key_exists($mutator, $this->taken);
    }
}
