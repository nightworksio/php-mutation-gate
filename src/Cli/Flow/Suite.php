<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFile;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFiles;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;
use function str_contains;

/**
 * The test directories as they are on disk: each package's `tests`, every
 * file there with what it holds, and every file outside them.
 */
final readonly class Suite
{
    /** Where a package keeps its tests. */
    /** @param array<string, Contents> $contents each test file's contents, by its path */
    private function __construct(
        private Paths $directories,
        private TestFiles $files,
        private Sources $sources,
        private Fingerprints $outside,
        private array $contents,
    ) {
    }

    public static function read(Trees $trees, Fingerprints $files, Directory $project): self|CannotJudge
    {
        $directories = self::directoriesOf($trees);
        $read = [];
        $contentsOf = [];
        $sources = Sources::none();
        $outside = Fingerprints::none();

        foreach ($files as $file) {
            if (! self::isWithin($file->path(), $directories)) {
                $outside = $outside->with($file);

                continue;
            }

            $contents = $project->read($file->path());

            if ($contents instanceof CannotJudge) {
                return $contents;
            }

            if ($contents instanceof Contents) {
                $read[] = self::testFile($file, $contents);
                $contentsOf[$file->path()->value()] = $contents;
                $sources = $sources->withNow($file->path(), $contents);
            }
        }

        return new self($directories, TestFiles::of(...$read), $sources, $outside, $contentsOf);
    }

    /** Each package's test directory. */
    public function directories(): Paths
    {
        return $this->directories;
    }

    /** Every file of the test directories, each as the content key reads it. */
    public function files(): TestFiles
    {
        return $this->files;
    }

    /** Every file of the test directories with what it holds, as reach reads them. */
    public function sources(): Sources
    {
        return $this->sources;
    }

    /** Every file outside the test directories. */
    public function outside(): Fingerprints
    {
        return $this->outside;
    }

    /**
     * The test files that name a group as a string, such as `->group('mutation-canary')`
     * does: over-read on purpose, since a file that names it for another reason
     * only makes the keys it reaches stricter.
     */
    public function naming(Group $group): Paths
    {
        $naming = Paths::none();
        $quoted = [sprintf("'%s'", $group->name()), sprintf('"%s"', $group->name())];

        foreach ($this->contents as $path => $contents) {
            $names = str_contains($contents->text(), $quoted[0]) || str_contains($contents->text(), $quoted[1]);
            $naming = $names ? $naming->with(Path::of($path)) : $naming;
        }

        return $naming;
    }

    /** What the `#[Holds]` in the test files declare. */
    public function holdings(): Holdings
    {
        $holdings = Holdings::none();

        foreach ($this->contents as $contents) {
            $holdings = $holdings->merge(PhpFile::read($contents)->holdings());
        }

        return $holdings;
    }

    private static function directoriesOf(Trees $trees): Paths
    {
        $tests = TestsDirectory::conventional();
        $directories = Paths::of($tests);

        foreach ($trees as $tree) {
            $package = $tree->package()->path()->value();
            $directories = $directories->with(Path::of(sprintf('%s/%s', $package, $tests->value())));
        }

        return $directories;
    }

    private static function isWithin(Path $path, Paths $directories): bool
    {
        foreach ($directories as $directory) {
            if ($path->within($directory)) {
                return true;
            }
        }

        return false;
    }

    private static function testFile(Fingerprint $file, Contents $contents): TestFile
    {
        return $file->path()->isTestCase()
            ? TestFile::testCase($file, $contents)
            : TestFile::other($file, $contents);
    }
}
