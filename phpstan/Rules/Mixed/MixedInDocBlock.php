<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\Mixed;

use function mb_strtolower;

use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\NodeVisitor;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Whether a doc block writes `mixed` anywhere in a type: a tag's own type, a
 * generic's argument, a closure's parameter or return, a shape's field.
 */
final class MixedInDocBlock implements NodeVisitor
{
    private const string MIXED = 'mixed';

    private bool $found = false;

    public static function in(string $docBlock): bool
    {
        $config = new ParserConfig([]);
        $constants = new ConstExprParser($config);
        $parser = new PhpDocParser($config, new TypeParser($config, $constants), $constants);
        $parsed = $parser->parse(new TokenIterator(new Lexer($config)->tokenize($docBlock)));

        $visitor = new self();
        new NodeTraverser([$visitor])->traverse([$parsed]);

        return $visitor->found;
    }

    /**
     * @param array<Node> $nodes
     *
     * @return array<Node>
     */
    public function beforeTraverse(array $nodes): array
    {
        return $nodes;
    }

    public function enterNode(Node $node): Node
    {
        if ($node instanceof IdentifierTypeNode && mb_strtolower($node->name) === self::MIXED) {
            $this->found = true;
        }

        return $node;
    }

    public function leaveNode(Node $node): Node
    {
        return $node;
    }

    /**
     * @param array<Node> $nodes
     *
     * @return array<Node>
     */
    public function afterTraverse(array $nodes): array
    {
        return $nodes;
    }
}
