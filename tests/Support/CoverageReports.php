<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_map;
use function basename;
use function file_get_contents;
use function glob;
use function is_file;
use function json_decode;

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\TimedTests;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Addition;
use NightWorksIO\MutationGate\Core\Hold\Additions;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use ReflectionClassConstant;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

use function sort;
use function sprintf;

/**
 * The corpus of php-code-coverage PHP reports under tests/Fixtures/CoverageReports, each under the root
 * `/project`: a real PHPUnit run under PCOV, its base path moved there, and reports written to reach each
 * edge of the format. Each holds `pest.json` and `native.json`, the map text each runner's reading of it
 * wrote before that reading read each line's tests against the list once, and where it has
 * `admission.json`, `admitted.json`, the map the suites admitted with those tests leaving and those holds.
 */
final readonly class CoverageReports
{
    /** The root every report's base path is under. */
    public const string ROOT = '/project';

    /** The php-code-coverage serialization format every report of the corpus is written in. */
    public const int FORMAT = 4;

    /** The commit every report's map is measured at. */
    private const string COMMIT = '0123456789abcdef0123456789abcdef01234567';

    /** Whether the installed php-code-coverage reads the corpus's format, which its lowest release does not. */
    public static function areReadable(): bool
    {
        return new ReflectionClassConstant(Serializer::class, 'SERIALIZATION_FORMAT')->getValue() === self::FORMAT;
    }

    /** @return array<string, string> each report's directory, by its name */
    public static function all(): array
    {
        $all = [];
        $directories = glob(Tree::at('tests/Fixtures/CoverageReports/*'), GLOB_ONLYDIR);

        foreach ($directories === false ? [] : $directories as $directory) {
            $all[basename($directory)] = $directory;
        }

        return $all;
    }

    /** @return array<string, string> the directory of each report with an admission, by its name */
    public static function admitting(): array
    {
        return array_filter(
            self::all(),
            static fn(string $directory): bool => is_file(sprintf('%s/admission.json', $directory)),
        );
    }

    /** The text a file of a report's directory holds. */
    public static function text(string $directory, string $name): string
    {
        return sprintf('%s', file_get_contents(sprintf('%s/%s', $directory, $name)));
    }

    /** The admission of a report's map its `admission.json` writes: its tests leaving, and the holds that keep them. */
    public static function admission(string $directory, MapOutline $outline): Admission
    {
        /** @var array{leaving: list<string>, holds: list<array{path: string, tests: list<string>}>} $read */
        $read = json_decode(self::text($directory, 'admission.json'), associative: true);
        $additions = Additions::none();

        foreach ($read['holds'] as $hold) {
            $additions = $additions->with(Addition::of(Path::of($hold['path']), self::ids($hold['tests']), $hold['path']));
        }

        return Admission::of($additions->staying(self::ids($read['leaving']), $outline));
    }

    /** A report as Pest's run leaves it, with what the run timed beside it. */
    public static function ran(string $directory): RanReport
    {
        return RanReport::at(
            sprintf('%s/coverage.php', $directory),
            self::FORMAT,
            TimedTests::beside(sprintf('%s/timed-tests.jsonl', $directory)),
            self::project()->relative(...),
        );
    }

    /** The map Pest's reading builds of a report, with what the run timed beside it. */
    public static function map(string $directory): CoverageMap
    {
        $file = CoverageFile::at(sprintf('%s/coverage.php', $directory));

        return self::ran($directory)->recorded()->onto($file instanceof CoverageFile ? $file->map(self::project()) : CoverageMap::of());
    }

    /**
     * What the gate decides of a report's map, read from an outline of it: admitted where the report has an
     * admission and it is asked for, measured at a commit, and keyed by each file's own digest.
     */
    public static function conversion(string $directory, MapOutline $outline, bool $admitted): Conversion
    {
        $keys = EntryKeys::none();

        foreach (self::map($directory)->files() as $file) {
            $keys = $keys->with($file, Digest::sha256Of($file->value()));
        }

        return Conversion::of(
            $outline,
            $admitted ? self::admission($directory, $outline) : NotGiven::value(),
            MeasuredAt::of(Revision::ref(self::COMMIT), dirty: true),
            $keys,
        );
    }

    /** The text the gate writes of a report's map, decided as {@see Conversion()} decides it. */
    public static function written(string $directory, bool $admitted): string
    {
        $map = self::map($directory);
        $conversion = self::conversion($directory, MapOutline::of($map), $admitted);
        $admission = $conversion->admission();

        return MapText::of($admission instanceof Admission ? $admission->applied($map) : $map)
            ->written($conversion->at(), $conversion->keys());
    }

    /** @return array{list<string>, array<string, list<string>>} an outline's tests in order, and the paths each ran, sorted */
    public static function outlined(MapOutline $outline): array
    {
        $tests = [];
        $ran = [];

        foreach ($outline->tests() as $test) {
            $tests[] = $test->value();
            $paths = array_map(static fn(Path $path): string => $path->value(), [...$outline->ran($test)]);
            sort($paths);
            $ran[$test->value()] = $paths;
        }

        return [$tests, $ran];
    }

    private static function project(): Project
    {
        return Project::at(self::ROOT, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    }

    /** @param list<string> $ids */
    private static function ids(array $ids): TestIds
    {
        return TestIds::of(...array_map(TestId::of(...), $ids));
    }
}
