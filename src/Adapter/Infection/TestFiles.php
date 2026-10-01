<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestClassFiles;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/** The files in the test directories that declare some test classes, found by their tokens. */
final readonly class TestFiles
{
    /** The files that declare the classes of these tests. */
    public static function declaring(Project $project, TestIds $tests): TestClassFiles
    {
        return self::found($project, TestClassFiles::of($tests));
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
        return self::found($project, TestClassFiles::wanting($classes))->byClass();
    }

    private static function found(Project $project, TestClassFiles $classes): TestClassFiles
    {
        foreach (PhpFiles::in($project, $project->tests()) as $file) {
            $path = $project->relative($file);
            $classes = $classes->mayDeclare($path)
                ? $classes->readIn($path, Contents::of(sprintf('%s', file_get_contents($file))))
                : $classes;
        }

        return $classes;
    }
}
