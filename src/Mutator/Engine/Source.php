<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_key_exists;
use function array_keys;
use function array_values;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
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
    /**
     * @param list<Stmt> $statements the code as parsed, which is never changed
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

        foreach ($this->changes($offered, $mutator) as $change) {
            $edits[] = $change->edit();
        }

        return Edits::of(...$edits);
    }

    /**
     * Every change these mutators make to a node each is offered, mutator by
     * mutator, each one's in the order the nodes stand. The code is read into
     * one tree, every mutator's nodes are found in one walk of it, and each
     * change is printed in a copy of its node's ancestors, so the tree every
     * change is made to is the code as it was.
     */
    public function changes(Offered $offered, Mutator ...$mutators): Changes
    {
        $tree = $this->fresh();
        $offers = $this->offers($tree, $offered, ...$mutators);
        $printer = new Standard();
        $changes = [];

        foreach (array_values($mutators) as $at => $mutator) {
            foreach (array_key_exists($at, $offers) ? $offers[$at] : [] as $node) {
                $change = $mutator->mutate($node);

                if (! $change instanceof Unchanged) {
                    $printed = $this->printedWith($tree, $node, $change, $offered, $printer);
                    $start = Line::of($node->getStartLine());
                    $edit = Edit::of($start, Line::of($node->getEndLine()), $this->code, $printed);
                    $changes[] = Change::of($mutator, $edit);
                }
            }
        }

        return Changes::of(...$changes);
    }

    /**
     * Where the changes these mutators make to a file start, counted without
     * making them: every node, anywhere in the code, is offered to each
     * mutator that handles its class, which a mutator's contract allows since
     * it never changes the node it is given. Each mutator that changes a node
     * counts once, as it makes at most one change per node. A change that
     * would print the same code counts, where `edits()` makes no mutant of it.
     */
    public function sites(Path $file, Mutator ...$mutators): MutantSites
    {
        $handlers = Handlers::of(...$mutators);
        $handling = [];
        $starts = [];

        foreach (new NodeFinder()->findInstanceOf($this->fresh(), Node::class) as $node) {
            $class = $node::class;

            if (! array_key_exists($class, $handling)) {
                $handling[$class] = [...$handlers->handling($node)];
            }

            foreach ($handling[$class] as $mutator) {
                if (! $mutator->mutate($node) instanceof Unchanged) {
                    $starts[] = Line::of($node->getStartLine());
                }
            }
        }

        return MutantSites::inFile($file, ...$starts);
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
     * The nodes each mutator is offered in a tree, by the mutator's position,
     * in the order they stand: each node is offered to the mutators that
     * handle its class, found once for the class.
     *
     * @param  list<Node>             $tree
     * @return array<int, list<Node>>
     */
    private function offers(array $tree, Offered $offered, Mutator ...$mutators): array
    {
        $handlers = Handlers::of(...$mutators);
        $handling = [];
        $offers = [];

        foreach (new NodeFinder()->findInstanceOf($tree, Node::class) as $node) {
            $class = $node::class;

            if (! array_key_exists($class, $handling)) {
                $handling[$class] = array_keys(iterator_to_array($handlers->handling($node), preserve_keys: true));
            }

            if ($offered === Offered::Everywhere || $this->inClassMethod($node)) {
                foreach ($handling[$class] as $at) {
                    $offers[$at][] = $node;
                }
            }
        }

        return $offers;
    }

    /** Whether a node is a class's method or stands inside one. */
    private function inClassMethod(Node $node): bool
    {
        $parent = $node->getAttribute(Mutator::PARENT);

        return $node instanceof ClassMethod || ($parent instanceof Node && $this->inClassMethod($parent));
    }

    /**
     * The code printed with a change in the place of the node it was made
     * for, as the runner would put it; the change's own attributes are as
     * they were once it is printed.
     *
     * @param list<Node> $tree
     */
    private function printedWith(
        array $tree,
        Node $node,
        Node|Removal $change,
        Offered $offered,
        Standard $printer,
    ): string {
        $attributes = $change instanceof Node ? $change->getAttributes() : [];
        $replaced = [...Replaced::in($node, $offered->replacing($node, $change), ...$tree)];
        $printed = $printer->printFormatPreserving($replaced, $this->statements, $this->tokens);

        if ($change instanceof Node) {
            $change->setAttributes($attributes);
        }

        return $printed;
    }

    private static function parser(): Parser
    {
        return new ParserFactory()->createForNewestSupportedVersion();
    }
}
