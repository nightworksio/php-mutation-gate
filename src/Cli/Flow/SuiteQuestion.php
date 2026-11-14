<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_values;
use function in_array;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFiles;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Test\Role;

/**
 * The question a run asks Outbreak's static graph of a suite (ADR-0132):
 * every PHP file outside the test directories and every support file a
 * source, every file of test cases a test, and every other file of the test
 * directories, such as `tests/Pest.php`, loaded by every test; about the PHP
 * files a change touched, a file it deleted or moved away read from its text
 * at the base.
 *
 * @internal
 */
final readonly class SuiteQuestion
{
    /** The question of the graph of a suite's files as they are on disk, asking nothing yet. */
    public static function over(Suite $suite): GraphQuestion
    {
        $files = $suite->files();

        return GraphQuestion::over(
            Paths::of(
                ...self::phpOutside($suite->outside()),
                ...self::phpIn($files, Role::Support, Role::Loaded, Role::Elsewhere),
            ),
            Paths::of(...self::phpIn($files, Role::TestCase)),
            Paths::of(...self::phpIn($files, Role::Loaded)),
        );
    }

    /**
     * The question over a suite, about every PHP file these changes touched,
     * each the disk no longer holds given by its text at the base.
     */
    public static function aboutChanges(
        Suite $suite,
        Layout $layout,
        Packages $packages,
        Sources $sources,
        Changes|CannotTell ...$changes,
    ): GraphQuestion {
        $question = self::over($suite);
        $about = self::touched(...$changes);

        foreach ($about as $path) {
            $gone = self::gone($question, $path, $sources);
            $question = $gone instanceof Contents
                ? $question->given($path, $gone, $layout->isTest($path->relativeTo($packages->holding($path)->path())))
                : $question;
        }

        return $question->about(Paths::of(...$about));
    }

    /**
     * Every PHP file these changes touched, where it is now and where it was.
     *
     * @return array<string, Path> by path
     */
    private static function touched(Changes|CannotTell ...$changes): array
    {
        $about = [];

        foreach ($changes as $changed) {
            foreach ($changed instanceof Changes ? $changed : Changes::none() as $change) {
                $about += self::php($change->path(), $change->previousPath());
            }
        }

        return $about;
    }

    /**
     * The PHP files of these.
     *
     * @return array<string, Path> by path
     */
    private static function php(Path ...$paths): array
    {
        $php = [];

        foreach ($paths as $path) {
            $php += $path->isPhp() ? [$path->value() => $path] : [];
        }

        return $php;
    }

    /** @return list<Path> */
    private static function phpOutside(Fingerprints $outside): array
    {
        $paths = [];

        foreach ($outside as $file) {
            $paths[] = $file->path();
        }

        return array_values(self::php(...$paths));
    }

    /**
     * The PHP files of the test directories of these roles.
     *
     * @return list<Path>
     */
    private static function phpIn(TestFiles $files, Role ...$roles): array
    {
        $paths = [];

        foreach ($files as $file) {
            $paths = in_array($file->role(), $roles, strict: true)
                ? [...$paths, $file->fingerprint()->path()]
                : $paths;
        }

        return array_values(self::php(...$paths));
    }

    /** The text at the base of a file the disk no longer holds; none where the disk holds it or no text was read. */
    private static function gone(GraphQuestion $question, Path $path, Sources $sources): Contents|NotGiven
    {
        $before = $question->sources()->has($path) || $question->tests()->has($path)
            ? NotGiven::value()
            : $sources->before($path);

        return $before instanceof Contents ? $before : NotGiven::value();
    }
}
