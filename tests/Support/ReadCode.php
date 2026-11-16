<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function array_values;

use LogicException;
use NightWorksIO\MutationGate\Mutator\Engine\Edit;
use NightWorksIO\MutationGate\Mutator\Engine\Printer;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\Token;

use function sprintf;

/**
 * A code read as the engine reads it, and a copy of its tree, every node
 * knowing its original and its parent, that its printer puts a change in.
 */
final readonly class ReadCode
{
    /**
     * @param list<Node>  $statements
     * @param list<Token> $tokens
     * @param list<Node>  $tree
     */
    private function __construct(
        private string $code,
        private array $statements,
        private array $tokens,
        private array $tree,
    ) {
    }

    public static function of(string $code): self
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $statements = array_values($parser->parse($code) ?? []);
        $tree = new NodeTraverser(new CloningVisitor(), new ParentConnectingVisitor())->traverse($statements);

        return new self($code, $statements, array_values($parser->getTokens()), array_values($tree));
    }

    /**
     * The printer's edit of a replacement in the place of the node of a
     * class at this position, counted in the order the nodes stand.
     *
     * @param class-string<Node> $class
     */
    public function edit(string $class, int $at, Node|int $replacement): Edit
    {
        $nodes = new NodeFinder()->findInstanceOf($this->tree, $class);

        if (! array_key_exists($at, $nodes)) {
            throw new LogicException(sprintf('No %s stands at %d.', $class, $at));
        }

        return Printer::of($this->code, $this->statements, $this->tokens)->edit($nodes[$at], $replacement, $this->tree);
    }
}
