<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_values;

use Closure;
use Pest\Mutate\Factories\NodeTraverserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

/**
 * A file's code as Pest makes its mutants of it, parsed once rather than
 * once a mutant: each attempt to make a mutant is handed a copy of the one
 * parse, which it may change, and each mutant's diff is taken against the
 * code printed once. Only the last code is kept, as Pest makes one file's
 * mutants after another's.
 *
 * @internal
 */
final class ParsedSource
{
    /** @var array<string, array<Node>> the last code parsed, by itself */
    private static array $parsed = [];

    /** @var array<string, string> the last code printed, by itself */
    private static array $printed = [];

    /**
     * A copy of the code's statements, as a fresh parse by this gives them.
     *
     * @param  Closure(string): (array<Node\Stmt>|null) $parse
     * @return array<Node>
     */
    public static function statements(Closure $parse, string $code): array
    {
        if (! array_key_exists($code, self::$parsed)) {
            self::$parsed = [$code => $parse($code) ?? []];
        }

        return new NodeTraverser(new class extends NodeVisitorAbstract {
            public function enterNode(Node $node): Node
            {
                return clone $node;
            }
        })->traverse(self::$parsed[$code]);
    }

    /**
     * Those of these mutators that some node of the code can take, as Pest's
     * attempts at a mutant see each node: its names resolved and its parent
     * connected. An attempt with any other makes no mutant, and walks the
     * whole file to find so; one walk here asks every mutator at once.
     *
     * @param  array<int, string>                     $mutators
     * @param  Closure(string, Node): bool            $takes    whether a mutator takes a node, as Pest asks it
     * @param  Closure(string): (array<Node\Stmt>|null) $parse
     * @return array<int, string>
     */
    public static function taking(array $mutators, Closure $takes, Closure $parse, string $code): array
    {
        $asked = new TakenMutators(array_values($mutators), $takes);
        $traverser = NodeTraverserFactory::create();
        $traverser->addVisitor($asked);
        $traverser->traverse(self::statements($parse, $code));

        return array_filter($mutators, $asked->takes(...));
    }

    /**
     * The code parsed by this and printed as Pest prints a mutant's file, so
     * their diff shows the mutant alone.
     *
     * @param Closure(string): (array<Node\Stmt>|null) $parse
     */
    public static function printed(Closure $parse, string $code): string
    {
        if (! array_key_exists($code, self::$printed)) {
            self::$printed = [$code => new Standard()->prettyPrintFile($parse($code) ?? [])];
        }

        return self::$printed[$code];
    }
}
