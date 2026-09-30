<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;
use function array_values;
use function file_get_contents;
use function mb_strrpos;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
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
    /** @param list<string> $classes fully qualified */
    public static function declaring(Project $project, array $classes): Paths
    {
        return Paths::of(...array_values(self::byClass($project, $classes)));
    }

    /**
     * The file that declares each of these classes, by the class, less any
     * class no such file declares.
     *
     * @param  list<string>        $classes fully qualified
     * @return array<string, Path>
     */
    public static function byClass(Project $project, array $classes): array
    {
        $wanted = [];

        foreach ($classes as $class) {
            $wanted[self::shortName($class)][] = $class;
        }

        $files = [];

        foreach (PhpFiles::in($project, $project->tests()) as $file) {
            $path = $project->relative($file);
            $name = $path->stem();
            $files += array_key_exists($name, $wanted) ? self::declaredIn($path, $file, $wanted[$name]) : [];
        }

        return $files;
    }

    /**
     * Those of these classes a file declares, each with the file.
     *
     * @param  list<string>        $classes
     * @return array<string, Path>
     */
    private static function declaredIn(Path $path, string $file, array $classes): array
    {
        $declared = PhpFile::read(Contents::of(sprintf('%s', file_get_contents($file))))->declares();
        $files = [];

        foreach ($classes as $class) {
            $files += $declared->meet(Names::of($class)) ? [$class => $path] : [];
        }

        return $files;
    }

    private static function shortName(string $class): string
    {
        $separator = mb_strrpos($class, '\\');

        return $separator === false ? $class : mb_substr($class, $separator + 1);
    }
}
