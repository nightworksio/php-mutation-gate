<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_all;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function file_get_contents;
use function in_array;
use function is_string;
use function mb_strlen;
use function mb_substr;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

use function sort;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * One PHP file of the repository, read with the parser rather than loaded, so
 * a file that would not compile, or that declares something the autoloader
 * cannot reach, is still read.
 */
final readonly class Source
{
    /** @param list<Node> $statements the file's statements, with every name resolved */
    private function __construct(public string $path, private array $statements)
    {
    }

    /** The file at a path relative to the repository. */
    public static function at(string $path): self
    {
        $code = file_get_contents(Tree::at($path));
        $statements = new ParserFactory()->createForHostVersion()->parse(is_string($code) ? $code : '') ?? [];
        $traverser = new NodeTraverser(new NameResolver());

        return new self($path, array_values($traverser->traverse($statements)));
    }

    /**
     * Every file under a directory, read.
     *
     * @return list<self>
     */
    public static function under(string $directory): array
    {
        return array_map(self::at(...), Tree::filesUnder($directory));
    }

    public function namespace(): string
    {
        $namespace = new NodeFinder()->findFirstInstanceOf($this->statements, Namespace_::class);

        return $namespace instanceof Namespace_ && $namespace->name instanceof Name ? $namespace->name->toString() : '';
    }

    /**
     * Every class-like the file declares, by its fully-qualified name.
     *
     * @return list<string>
     */
    public function declares(): array
    {
        $found = [];

        foreach (new NodeFinder()->findInstanceOf($this->statements, ClassLike::class) as $class) {
            if ($class->namespacedName instanceof Name) {
                $found[] = $class->namespacedName->toString();
            }
        }

        return $found;
    }

    /** Whether the file declares at least one type, and every type it declares is an interface. */
    public function declaresOnlyInterfaces(): bool
    {
        $declared = new NodeFinder()->findInstanceOf($this->statements, ClassLike::class);

        return $declared !== [] && array_all($declared, static fn(ClassLike $class): bool => $class instanceof Interface_);
    }

    /**
     * Every class, interface, trait or enum the file names, imports included,
     * fully qualified and in byte order. A function or a constant is not a
     * class and is left out.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_values(array_unique([...$this->written(), ...$this->imported()]));
        sort($names);

        return $names;
    }

    /** The class PSR-4 says a file at this path declares, or nothing where no PSR-4 root holds it. */
    public static function classAtPath(string $path): string
    {
        foreach (Tree::namespaces() as $directory => $namespace) {
            if (str_starts_with($path, $directory)) {
                return sprintf('%s%s', $namespace, str_replace('/', '\\', mb_substr($path, mb_strlen($directory), -mb_strlen('.php'))));
            }
        }

        return '';
    }

    /**
     * Every fully-qualified name the file writes in a class position.
     *
     * @return list<string>
     */
    private function written(): array
    {
        $finder = new NodeFinder();
        $notClasses = [
            ...array_map(static fn(FuncCall $call): Node => $call->name, $finder->findInstanceOf($this->statements, FuncCall::class)),
            ...array_map(static fn(ConstFetch $fetch): Node => $fetch->name, $finder->findInstanceOf($this->statements, ConstFetch::class)),
        ];

        return array_values(array_map(
            static fn(FullyQualified $name): string => $name->toString(),
            array_filter(
                $finder->findInstanceOf($this->statements, FullyQualified::class),
                static fn(FullyQualified $name): bool => ! in_array($name, $notClasses, strict: true),
            ),
        ));
    }

    /**
     * Every class the file imports.
     *
     * @return list<string>
     */
    private function imported(): array
    {
        $found = [];

        foreach (new NodeFinder()->findInstanceOf($this->statements, Use_::class) as $use) {
            foreach ($use->uses as $item) {
                if ($use->type === Use_::TYPE_NORMAL || $item->type === Use_::TYPE_NORMAL) {
                    $found[] = $item->name->toString();
                }
            }
        }

        return $found;
    }
}
