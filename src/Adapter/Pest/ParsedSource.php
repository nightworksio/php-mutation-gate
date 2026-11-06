<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;

use Closure;
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
