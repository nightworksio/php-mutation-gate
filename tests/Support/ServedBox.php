<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Composer\Autoload\ClassLoader;

use function constant;
use function is_scalar;
use function realpath;
use function sprintf;

/**
 * A class file in a namespace no other test declares, a copy of it with its
 * size raised, and a Composer loader that loads the class from the file:
 * for a test of serving a copy in a class file's place.
 */
final readonly class ServedBox
{
    private const string CODE = <<<'PHP'
        <?php
        namespace %s;
        %s Box
        {
            public const SIZE = %d;
            public const FILE = __FILE__;
            public const DIRECTORY = __DIR__;
        }

        PHP;

    private function __construct(
        public string $class,
        public string $file,
        public string $copy,
        public ClassLoader $loader,
    ) {
    }

    /** A box, declared as this kind of class-like, whose loader is registered; a test unregisters it once done. */
    public static function registered(string $kind = 'final class'): self
    {
        $namespace = Declared::name('Served');
        $root = (string) realpath(Scratch::directory());
        Scratch::write($root, 'src/Box.php', sprintf(self::CODE, $namespace, $kind, 1));
        Scratch::write($root, 'mutations/box.php', sprintf(self::CODE, $namespace, $kind, 2));
        $class = sprintf('%s\Box', $namespace);
        $loader = new ClassLoader(sprintf('%s/vendor', $root));
        $loader->addClassMap([$class => sprintf('%s/src/Box.php', $root)]);
        $loader->register();

        return new self($class, sprintf('%s/src/Box.php', $root), sprintf('%s/mutations/box.php', $root), $loader);
    }

    /** A constant of the box's class, loading the class where it is not loaded yet, as text. */
    public function read(string $constant): string
    {
        $value = constant(sprintf('%s::%s', $this->class, $constant));

        return is_scalar($value) ? (string) $value : '';
    }
}
