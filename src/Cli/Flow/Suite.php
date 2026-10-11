<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_any;
use function array_filter;

use const ARRAY_FILTER_USE_KEY;

use function array_keys;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Project\DirectoryGlob;
use NightWorksIO\MutationGate\Adapter\Project\PhpUnitSuite;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Hold\PestHolds;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFile;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFiles;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;
use function str_contains;

/**
 * The test suite as it is on disk: the one the PHPUnit config declares and
 * every other package's `tests`, every file there with what it holds, and
 * every file outside them.
 */
final readonly class Suite
{
    /** @param array<string, Contents> $contents each test file's contents, by its path */
    private function __construct(
        private PhpUnitSuite $configured,
        private TestFiles $files,
        private Sources $sources,
        private Fingerprints $outside,
        private array $contents,
    ) {
    }

    public static function read(Trees $trees, Fingerprints $files, Directory $project): self|CannotJudge
    {
        $configured = self::configured($project);

        return $configured instanceof PhpUnitSuite ? self::readIn($trees, $files, $project, $configured) : $configured;
    }

    /**
     * Where each package keeps its tests, spelt from its directory, as the
     * PHPUnit config names them, each with the suffix of its files of test
     * cases.
     *
     * @return non-empty-list<SuiteDirectory>
     */
    public function directories(): array
    {
        return $this->configured->directories();
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

    /**
     * The `#[Holds]` in the files of these suites' tests as Pest's plugin
     * reads them, where Pest loads these files before it starts any plugin
     * and reads them whatever suite runs; or the first from which no group
     * can follow.
     */
    public function pestHolds(Paths $first, Suites $among): PestHolds|CannotJudge
    {
        $holds = PestHolds::after($first);

        foreach ($this->contentsAmong($first, $among) as $path => $contents) {
            $holds = $holds->read(Path::of($path), PhpFile::read($contents)->holds());

            if ($holds instanceof CannotJudge) {
                return $holds;
            }
        }

        return $holds;
    }

    /** What the `#[Holds]` in the files of these suites' tests declare: a suite neither list names judges nothing. */
    public function holdings(Suites $among): Holdings
    {
        $holdings = Holdings::none();

        foreach ($this->contentsAmong(Paths::none(), $among) as $contents) {
            $holdings = $holdings->merge(PhpFile::read($contents)->holdings());
        }

        return $holdings;
    }

    /** Of some test files, those these suites' tests are in. */
    public function among(Paths $files, Suites $suites): Paths
    {
        return $this->configured->suites()->among($files, $suites);
    }

    /**
     * The suite the first PHPUnit config the project has declares, its
     * wildcards expanded from the project's root; the conventional one where
     * it has none.
     */
    public static function configured(Directory $project): PhpUnitSuite|CannotJudge
    {
        foreach (PhpUnitConfig::candidatesIn(Path::root()) as $candidate) {
            $contents = $project->read($candidate);

            if ($contents instanceof Contents || $contents instanceof CannotJudge) {
                return $contents instanceof Contents
                    ? PhpUnitSuite::declaredIn($contents, $candidate, DirectoryGlob::from($project->root()))
                    : $contents;
            }
        }

        return PhpUnitSuite::conventional();
    }

    /**
     * The directories a package's tests are in, as its own PHPUnit config
     * declares them, read as the root's is: what a runner rooted in the
     * package is told (ADR-0005, decision 7).
     */
    public static function testsIn(Directory $project, Path $package): Paths|CannotJudge
    {
        $suite = self::configured(Directory::at($project->root()->at($package)->value()));

        return $suite instanceof PhpUnitSuite ? $suite->paths() : $suite;
    }

    /** The suite, of the test files the PHPUnit config declares and those of every other package's tests. */
    private static function readIn(
        Trees $trees,
        Fingerprints $files,
        Directory $project,
        PhpUnitSuite $configured,
    ): self|CannotJudge {
        $packages = self::packageTestsOf($trees);
        $read = [];
        $contentsOf = [];
        $sources = Sources::none();
        $outside = Fingerprints::none();

        foreach ($files as $file) {
            if (! self::isIn($file->path(), $configured, $packages, $trees)) {
                $outside = $outside->with($file);

                continue;
            }

            $contents = $project->read($file->path());

            if ($contents instanceof CannotJudge) {
                return $contents;
            }

            if ($contents instanceof Contents) {
                $read[] = self::isTestCase($file->path(), $configured, $packages)
                    ? TestFile::testCase($file, $contents)
                    : TestFile::other($file, $contents);
                $contentsOf[$file->path()->value()] = $contents;
                $sources = $sources->withNow($file->path(), $contents);
            }
        }

        return new self(
            $configured,
            TestFiles::of(...$read),
            $sources,
            $outside,
            $contentsOf,
        );
    }

    /**
     * The test directory of each package other than the project's root, with PHPUnit's suffix.
     *
     * @return list<SuiteDirectory>
     */
    private static function packageTestsOf(Trees $trees): array
    {
        $tests = TestsDirectory::conventional();
        $directories = [];

        foreach ($trees as $tree) {
            $package = $tree->package()->path();
            $directory = Path::of(sprintf('%s/%s', $package->value(), $tests->value()));
            $directories = $package->equals(Path::root())
                ? $directories
                : [...$directories, $directory->value() => SuiteDirectory::of($directory, '')];
        }

        return array_values($directories);
    }

    /**
     * Whether a file is one of the suite's: the configured one's, or in
     * another package's tests. A file a tree holds is source whatever the
     * config says, unless it is a file of test cases kept beside the source,
     * so every key reads it (ADR-0007, decision 2).
     *
     * @param list<SuiteDirectory> $packages
     */
    private static function isIn(Path $path, PhpUnitSuite $configured, array $packages, Trees $trees): bool
    {
        $tested = $configured->holds($path)
            || array_any($packages, static fn(SuiteDirectory $tests): bool => $tests->holds($path));

        return $tested && (! $trees->holding($path) instanceof Tree || self::isTestCase($path, $configured, $packages));
    }

    /**
     * Whether a file is one of test cases: named by its suite, or with the
     * suffix of the test directory it is in.
     *
     * @param list<SuiteDirectory> $packages
     */
    private static function isTestCase(Path $path, PhpUnitSuite $configured, array $packages): bool
    {
        return $configured->holdsTestCase($path)
            || array_any($packages, static fn(SuiteDirectory $tests): bool => $tests->holdsTestCase($path));
    }

    /**
     * The contents of these files, and of each test file of these suites.
     *
     * @return array<string, Contents> by path
     */
    private function contentsAmong(Paths $first, Suites $among): array
    {
        $files = Paths::of(...array_map(Path::of(...), array_keys($this->contents)));
        $kept = $this->among($files, $among)->and($first);

        return array_filter(
            $this->contents,
            static fn(string $path): bool => $kept->has(Path::of($path)),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
