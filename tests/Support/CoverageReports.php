<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_map;
use function basename;

use Closure;

use function file_get_contents;
use function glob;
use function is_file;
use function json_decode;

use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Addition;
use NightWorksIO\MutationGate\Core\Hold\Additions;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

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

    /** The admission a report's `admission.json` writes: its tests leaving, and the holds that keep them. */
    public static function admission(string $directory): Admission
    {
        /** @var array{leaving: list<string>, holds: list<array{path: string, tests: list<string>}>} $read */
        $read = json_decode(self::text($directory, 'admission.json'), associative: true);
        $additions = Additions::none();

        foreach ($read['holds'] as $hold) {
            $additions = $additions->with(Addition::of(Path::of($hold['path']), self::ids($hold['tests']), $hold['path']));
        }

        return Admission::of(
            self::ids($read['leaving']),
            static fn(Path $file): Closure => static fn(TestId $test): bool => $additions->on($file)->adds($test, $file),
        );
    }

    /** @param list<string> $ids */
    private static function ids(array $ids): TestIds
    {
        return TestIds::of(...array_map(TestId::of(...), $ids));
    }
}
