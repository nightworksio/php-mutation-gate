<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Testing;

use function array_diff_key;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function min;

use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\FindingVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Token;

use function sprintf;

/**
 * A mutator's changes to a snippet of PHP under each runner, as the bridges
 * the gate writes make them, without either runner installed (ADR-0021
 * decision 8). Each node is offered with its names resolved, in a
 * `resolvedName` attribute, and its `parent` set, as both runners offer it.
 */
final readonly class Mutates
{
    /** The attribute php-parser's parent connecting keeps a node's parent in. */
    private const string PARENT = 'parent';

    /**
     * @param list<Node\Stmt> $statements the snippet as parsed, which is never changed
     * @param list<Token> $tokens the snippet's tokens, which printing it again preserves
     */
    private function __construct(
        private Mutator $mutator,
        private string $code,
        private array $statements,
        private array $tokens,
    ) {
    }

    /** @throws NotParsed where the snippet is not PHP */
    public static function with(Mutator $mutator, string $php): self
    {
        $parser = self::parser();
        $errors = new Collecting();
        $statements = $parser->parse($php, $errors) ?? [];

        if ($errors->hasErrors()) {
            throw NotParsed::because($errors->getErrors()[0]->getMessage());
        }

        return new self($mutator, $php, array_values($statements), array_values($parser->getTokens()));
    }

    /** The changes Pest's bridge makes: one per node the mutator handles, anywhere in the snippet. */
    public function underPest(): Changes
    {
        return $this->changes(Offered::Everywhere);
    }

    /**
     * The changes Infection's bridge makes: one per node the mutator handles
     * in a class method or on its signature, which are the only nodes
     * Infection offers.
     */
    public function underInfection(): Changes
    {
        return $this->changes(Offered::InClassMethods);
    }

    private function changes(Offered $offered): Changes
    {
        $changes = [];
        $offers = count($this->offered($this->fresh(), $offered));

        for ($at = 0; $at < $offers; $at++) {
            $tree = $this->fresh();
            $node = $this->offered($tree, $offered)[$at];
            $change = $this->mutator->mutate($node);

            if (! $change instanceof Unchanged) {
                $mutated = new NodeTraverser($this->replacing($node, $change, $offered))->traverse($tree);
                $printed = new Standard()->printFormatPreserving($mutated, $this->statements, $this->tokens);
                $changes[] = $this->changed($printed);
            }
        }

        return Changes::of(...$changes);
    }

    /**
     * A copy of the snippet's statements, each node with its names resolved
     * and its parent set.
     *
     * @return list<Node>
     */
    private function fresh(): array
    {
        return array_values(new NodeTraverser(
            new CloningVisitor(),
            new NameResolver(null, ['replaceNodes' => false]),
            new ParentConnectingVisitor(),
        )->traverse($this->statements));
    }

    /**
     * The nodes a runner offers the mutator in a tree, in the order they stand.
     *
     * @param  list<Node> $tree
     * @return list<Node>
     */
    private function offered(array $tree, Offered $offered): array
    {
        $finding = new FindingVisitor(fn(Node $node): bool => $this->mutator->handles()->has($node)
            && ($offered === Offered::Everywhere || $this->inClassMethod($node)));
        new NodeTraverser($finding)->traverse($tree);

        return $finding->getFoundNodes();
    }

    private function inClassMethod(Node $node): bool
    {
        $at = $node;

        while ($at instanceof Node && ! $at instanceof ClassMethod) {
            $parent = $at->getAttribute(self::PARENT);
            $at = $parent instanceof Node ? $parent : false;
        }

        return $at instanceof ClassMethod;
    }

    /** The lines a change removes from the snippet, `-` first, then those that replace them, `+` first. */
    private function changed(string $mutated): string
    {
        $before = explode("\n", $this->code);
        $after = explode("\n", $mutated);
        $shorter = min(count($before), count($after));
        $first = 0;

        while ($first < $shorter && $before[$first] === $after[$first]) {
            $first++;
        }

        $last = 0;

        while ($last < $shorter - $first && $before[count($before) - 1 - $last] === $after[count($after) - 1 - $last]) {
            $last++;
        }

        $lines = [
            ...$this->marked('-', array_slice($before, $first, count($before) - $first - $last)),
            ...$this->marked('+', array_slice($after, $first, count($after) - $first - $last)),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  list<string> $lines
     * @return list<string>
     */
    private function marked(string $sign, array $lines): array
    {
        $marked = [];

        foreach ($lines as $line) {
            $marked[] = sprintf('%s%s', $sign, $line);
        }

        return $marked;
    }

    /**
     * A visitor that puts a change in the place of the node it was made for,
     * as a runner's bridge does. A node built from the original's attributes
     * loses `origNode`, which names the original's class and would make
     * php-parser's printer refuse it.
     */
    private function replacing(Node $target, Node|Removal $change, Offered $offered): NodeVisitor
    {
        return new class ($target, $change, $offered) extends NodeVisitorAbstract {
            /** The attribute php-parser's cloning keeps a node's original in. */
            private const string ORIGINAL = 'origNode';

            public function __construct(
                private readonly Node $target,
                private readonly Node|Removal $change,
                private readonly Offered $offered,
            ) {
            }

            public function leaveNode(Node $node): Node|int
            {
                return match (true) {
                    $node !== $this->target => $node,
                    $this->change instanceof Node => $this->withoutOriginal($this->change),
                    $this->offered === Offered::Everywhere => NodeVisitor::REMOVE_NODE,
                    default => new Nop(),
                };
            }

            private function withoutOriginal(Node $node): Node
            {
                $node->setAttributes(array_diff_key($node->getAttributes(), [self::ORIGINAL => true]));

                return $node;
            }
        };
    }

    private static function parser(): Parser
    {
        return new ParserFactory()->createForNewestSupportedVersion();
    }
}
