<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function explode;
use function is_string;
use function ltrim;

use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\NodeVisitor;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;

use function sprintf;
use function str_starts_with;

/**
 * The class names the types of a doc block's `@param`, `@return`, `@var` and
 * `@throws` tags spell, wherever in a type they stand: a generic's argument, a
 * closure's parameter or return, a shape's field. Each is resolved as PHP
 * resolves a name in the file that declares the class: through its imports,
 * or else in its namespace.
 */
final class DocTypes implements NodeVisitor
{
    /** @var array<string, array<string, string>> each file's imports, by its path, once it was read */
    private static array $imports = [];

    /** @var list<string> */
    private array $names = [];

    /**
     * @param  ReflectionClass<object> $declaring the class whose file the doc block is in
     * @return list<string>
     */
    public static function in(string|false $docBlock, ReflectionClass $declaring): array
    {
        if (! is_string($docBlock)) {
            return [];
        }

        $config = new ParserConfig([]);
        $constants = new ConstExprParser($config);
        $parser = new PhpDocParser($config, new TypeParser($config, $constants), $constants);
        $parsed = $parser->parse(new TokenIterator(new Lexer($config)->tokenize($docBlock)));
        $types = [];

        foreach ($parsed->getTags() as $tag) {
            $value = $tag->value;

            if ($value instanceof ParamTagValueNode || $value instanceof ReturnTagValueNode || $value instanceof VarTagValueNode || $value instanceof ThrowsTagValueNode) {
                $types[] = $value->type;
            }
        }

        $visitor = new self();
        new NodeTraverser([$visitor])->traverse($types);
        $imports = self::importsOf($declaring);
        $resolved = [];

        foreach ($visitor->names as $name) {
            $resolved[] = self::resolve($name, $imports, $declaring->getNamespaceName());
        }

        return $resolved;
    }

    /**
     * @param  array<Node> $nodes
     * @return array<Node>
     */
    public function beforeTraverse(array $nodes): array
    {
        return $nodes;
    }

    public function enterNode(Node $node): Node
    {
        if ($node instanceof IdentifierTypeNode) {
            $this->names[] = $node->name;
        }

        return $node;
    }

    public function leaveNode(Node $node): Node
    {
        return $node;
    }

    /**
     * @param  array<Node> $nodes
     * @return array<Node>
     */
    public function afterTraverse(array $nodes): array
    {
        return $nodes;
    }

    /**
     * @param  ReflectionClass<object> $class
     * @return array<string, string>
     */
    private static function importsOf(ReflectionClass $class): array
    {
        $file = $class->getFileName();

        if (! is_string($file)) {
            return [];
        }

        if (! array_key_exists($file, self::$imports)) {
            self::$imports[$file] = Source::at(Tree::relative($file))->imports();
        }

        return self::$imports[$file];
    }

    /** @param array<string, string> $imports each imported class, by the name the file gives it */
    private static function resolve(string $name, array $imports, string $namespace): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        [$first, $rest] = [...explode('\\', $name, 2), ''];

        return match (true) {
            array_key_exists($first, $imports) => $rest === '' ? $imports[$first] : sprintf('%s\\%s', $imports[$first], $rest),
            $namespace === '' => $name,
            default => sprintf('%s\\%s', $namespace, $name),
        };
    }
}
