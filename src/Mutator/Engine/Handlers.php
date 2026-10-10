<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function class_implements;
use function class_parents;
use function ksort;

use NightWorksIO\MutationGate\Mutator\Mutator;
use PhpParser\Node;
use Traversable;

/**
 * The mutators of one run, each by its place, filed under each node class it
 * handles, so the mutators that handle a node are found by its class: those
 * filed under it, a class it extends or an interface it implements.
 */
final readonly class Handlers
{
    /** @param array<string, array<int, Mutator>> $byClass */
    private function __construct(private array $byClass)
    {
    }

    public static function of(Mutator ...$mutators): self
    {
        $byClass = [];

        foreach (array_values($mutators) as $place => $mutator) {
            foreach ($mutator->handles() as $class) {
                $byClass[$class][$place] = $mutator;
            }
        }

        return new self($byClass);
    }

    /**
     * The mutators that handle nodes of this node's class, each once, by its
     * place, in the order they were given.
     *
     * @return Traversable<int, Mutator>
     */
    public function handling(Node $node): Traversable
    {
        $class = $node::class;
        $handling = [];
        $parents = class_parents($class);
        $interfaces = class_implements($class);
        $kinds = [
            $class,
            ...$parents === false ? [] : $parents,
            ...$interfaces === false ? [] : $interfaces,
        ];

        foreach ($kinds as $kind) {
            $handling += array_key_exists($kind, $this->byClass) ? $this->byClass[$kind] : [];
        }

        ksort($handling);

        return new ArrayIterator($handling);
    }
}
