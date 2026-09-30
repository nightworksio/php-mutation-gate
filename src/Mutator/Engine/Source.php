<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_diff_key;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\File\Line;
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

/**
 * PHP code as php-parser reads it, and every change a mutator makes to it.
 * Each node is offered with its names resolved, in a `resolvedName`
 * attribute, and its `parent` set, as both runners offer it, and each change
 * is printed by php-parser's format-preserving printer, so only the lines the
 * changed node spans differ.
 *
 * @internal the engine's and the testing kit's own
 */
final readonly class Source
{
    /** The attribute php-parser's parent connecting keeps a node's parent in. */
    private const string PARENT = 'parent';

    /**
     * @param list<Node\Stmt> $statements the code as parsed, which is never changed
     * @param list<Token>     $tokens     the code's tokens, which printing it again preserves
     */
    private function __construct(private string $code, private array $statements, private array $tokens)
    {
    }

    public static function parse(string $php): self|Unparsable
    {
        $parser = self::parser();
        $errors = new Collecting();
        $statements = $parser->parse($php, $errors) ?? [];

        return $errors->hasErrors()
            ? Unparsable::because($errors->getErrors()[0]->getMessage())
            : new self($php, array_values($statements), array_values($parser->getTokens()));
    }

    /** Every change the mutator makes to a node it is offered, in the order the nodes stand. */
    public function edits(Mutator $mutator, Offered $offered): Edits
    {
        $edits = [];
        $offers = count($this->offered($this->fresh(), $mutator, $offered));

        for ($at = 0; $at < $offers; $at++) {
            $tree = $this->fresh();
            $node = $this->offered($tree, $mutator, $offered)[$at];
            $change = $mutator->mutate($node);

            if (! $change instanceof Unchanged) {
                $mutated = new NodeTraverser($this->replacing($node, $change, $offered))->traverse($tree);
                $printed = new Standard()->printFormatPreserving($mutated, $this->statements, $this->tokens);
                $start = Line::of($node->getStartLine());
                $edits[] = Edit::of($start, Line::of($node->getEndLine()), $this->code, $printed);
            }
        }

        return Edits::of(...$edits);
    }

    /**
     * A copy of the code's statements, each node with its names resolved and
     * its parent set.
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
     * The nodes a mutator is offered in a tree, in the order they stand.
     *
     * @param  list<Node> $tree
     * @return list<Node>
     */
    private function offered(array $tree, Mutator $mutator, Offered $offered): array
    {
        $finding = new FindingVisitor(fn(Node $node): bool => $mutator->handles()->has($node)
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

    /**
     * A visitor that puts a change in the place of the node it was made for.
     * A node built from the original's attributes loses `origNode`, which
     * names the original's class and would make php-parser's printer refuse
     * it.
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
