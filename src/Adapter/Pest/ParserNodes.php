<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;
use function explode;
use function implode;
use function is_a;
use function is_dir;
use function is_string;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use PhpParser\Node;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function sort;
use function sprintf;

/**
 * Every node class of the php-parser a project installed, named after the
 * files under its `Node` directory. Pest looks a mutator up by the exact
 * class of each node a file holds, so a bridge names each node class its
 * mutator handles, and each subclass of one: a mutator that handles an
 * abstract class or an interface is offered the nodes of its subclasses, as
 * every other runner offers them.
 */
final readonly class ParserNodes
{
    /** Where Composer installs php-parser's node classes, under a vendor directory. */
    private const string DIRECTORY = '%s/nikic/php-parser/lib/PhpParser/Node';

    /** @param list<string> $classes */
    private function __construct(private array $classes)
    {
    }

    /** The node classes of the php-parser installed in this vendor directory; none where none is. */
    public static function in(string $vendor): self
    {
        $directory = sprintf(self::DIRECTORY, $vendor);
        $classes = [];

        if (is_dir($directory)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($files as $pathname => $file) {
                $classes = is_string($pathname) ? [...$classes, ...self::named($directory, $pathname)] : $classes;
            }
        }

        sort($classes);

        return new self($classes);
    }

    /**
     * The classes a mutator handles, then each of these that is a subclass of
     * one, each once.
     *
     * @return list<class-string<Node>>
     */
    public function handled(NodeClasses $handles): array
    {
        $handled = [];

        foreach ($handles as $class) {
            $handled[$class] = $class;
        }

        foreach ($this->classes as $candidate) {
            foreach ($handles as $class) {
                if (is_a($candidate, $class, allow_string: true)) {
                    $handled[$candidate] = $candidate;
                }
            }
        }

        return array_values($handled);
    }

    /** @return list<string> the class a PHP file under the directory is named for, or none for another file */
    private static function named(string $directory, string $path): array
    {
        $file = Path::of(mb_substr($path, mb_strlen($directory) + 1));
        $folder = $file->directory();
        $segments = $folder->equals(Path::root()) ? [] : explode('/', $folder->value());

        return $file->isPhp() ? [implode('\\', [Node::class, ...$segments, $file->stem()])] : [];
    }
}
