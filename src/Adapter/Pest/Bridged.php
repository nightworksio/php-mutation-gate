<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Mutator\Engine\Offered;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use Pest\Mutate\Contracts\Mutator as PestMutator;
use Pest\Mutate\Support\MutatorMap;
use PhpParser\Node;

/**
 * What a bridge the gate writes for Pest does in Pest's process (ADR-0021):
 * it offers its mutator a node only where the mutator changes it, so Pest
 * makes no mutant of a node left alone, and puts each change in place as
 * the testing kit says Pest does. Registering a bridge adds it to
 * pest-plugin-mutate's map of mutators by node class, without which Pest
 * never applies a mutator it does not ship, and keeps its mutator's name,
 * which is the bridged mutant's name wherever the gate names it.
 */
final class Bridged
{
    /** @var array<string, string> each registered bridge's mutator's name, by the bridge's class */
    private static array $names = [];

    /** Loads the bridges the gate wrote into a file, where one is named and is there. */
    public static function load(string|false $bridges): void
    {
        if (is_string($bridges) && $bridges !== '' && is_file($bridges)) {
            require_once $bridges;
        }
    }

    /**
     * Adds a bridge to Pest's map of mutators under each node class it
     * handles, and keeps its mutator's name.
     *
     * @param class-string<PestMutator> $bridge
     * @param array<int, class-string<Node>> $nodes
     */
    public static function register(string $bridge, string $name, array $nodes): void
    {
        $map = MutatorMap::get();

        foreach ($nodes as $node) {
            $map[$node][] = $bridge;
        }

        MutatorMap::$map = $map;
        self::$names[$bridge] = $name;
    }

    /** A mutator's name as the gate names it: a bridged mutator's own, and any other's class, as Pest names it. */
    public static function nameOf(string $mutator): string
    {
        return array_key_exists($mutator, self::$names) ? self::$names[$mutator] : $mutator;
    }

    /** Whether the mutator changes this node: one it leaves alone is no mutant. */
    public static function can(Mutator $mutator, Node $node): bool
    {
        return $mutator->handles()->has($node) && ! $mutator->mutate($node) instanceof Unchanged;
    }

    /** What takes the node's place for the mutator's change to it, as Pest puts it in place; none, the node itself. */
    public static function mutate(Mutator $mutator, Node $node): Node|int
    {
        $change = $mutator->mutate($node);

        return $change instanceof Unchanged ? $node : Offered::Everywhere->replacing($node, $change);
    }
}
