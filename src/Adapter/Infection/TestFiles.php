<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;
use function basename;
use function file_get_contents;
use function mb_strrpos;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

use function sprintf;

/**
 * The test files that declare some test classes. PHPUnit loads a test class
 * from the file named after it, so only such files are read, and a file
 * counts when its tokens declare the class.
 */
final readonly class TestFiles
{
    private const string SUFFIX = '.php';

    /** @param list<string> $classes fully qualified */
    public static function declaring(Project $project, array $classes): Paths
    {
        $wanted = [];

        foreach ($classes as $class) {
            $wanted[self::shortName($class)][] = $class;
        }

        $files = Paths::none();

        foreach (PhpFiles::in($project, $project->tests()) as $file) {
            $name = basename($file, self::SUFFIX);

            if (array_key_exists($name, $wanted) && self::declares($file, $wanted[$name])) {
                $files = $files->with($project->relative($file));
            }
        }

        return $files;
    }

    /** @param list<string> $classes */
    private static function declares(string $file, array $classes): bool
    {
        $declared = PhpFile::read(Contents::of(sprintf('%s', file_get_contents($file))))->declares();

        return $declared->meet(Names::of(...$classes));
    }

    private static function shortName(string $class): string
    {
        $separator = mb_strrpos($class, '\\');

        return $separator === false ? $class : mb_substr($class, $separator + 1);
    }
}
