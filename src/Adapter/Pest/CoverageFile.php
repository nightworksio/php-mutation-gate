<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function array_sum;
use function array_unique;
use function array_values;
use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rtrim;

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Exception as CoverageFailure;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

use function sprintf;
use function str_ends_with;
use function trim;

/**
 * A coverage map as `--coverage-php` writes it, read with
 * phpunit/php-code-coverage: which tests ran each line of each file, by the
 * file's path on disk, and how long each test took.
 */
final readonly class CoverageFile
{
    /** How php-code-coverage ends a map it wrote whole. */
    private const string END = "END_OF_COVERAGE_SERIALIZATION\n);";

    private const string CUT_OFF = '%s cannot be read as a coverage map: it ends before the map does.';

    /**
     * @param array<string, array<int, array<int, string>>> $lines     the tests on each line, by file and line
     * @param array<string, float>                    $durations each test's seconds, by id
     */
    private function __construct(private array $lines, private array $durations)
    {
    }

    /** @param non-empty-string $file */
    public static function at(string $file): self|CannotJudge
    {
        if (! is_file($file)) {
            return CannotJudge::because(sprintf('There is no coverage map at %s, so no test runs any line.', $file));
        }

        if (! str_ends_with(trim(sprintf('%s', file_get_contents($file))), self::END)) {
            return CannotJudge::because(sprintf(self::CUT_OFF, $file));
        }

        return self::unserialized($file);
    }

    /** The map, with each file as the project spells it. */
    public function map(Project $project): CoverageMap
    {
        $map = CoverageMap::empty();

        foreach ($this->lines as $file => $lines) {
            $map = $this->covered($map, $project->relative($file), $lines);
        }

        foreach ($this->durations as $test => $seconds) {
            $map = $map->timed(TestId::of($test), Seconds::of($seconds));
        }

        return $map;
    }

    /** @return list<string> the tests that ran any line from the first to the last of a file, each once */
    public function testsCovering(string $file, int $first, int $last): array
    {
        $tests = [];

        foreach (array_key_exists($file, $this->lines) ? $this->lines[$file] : [] as $line => $covering) {
            if ($line >= $first && $line <= $last) {
                $tests = [...$tests, ...$covering];
            }
        }

        return array_values(array_unique($tests));
    }

    /** How long the whole suite took, one test after another. */
    public function seconds(): float
    {
        return array_sum($this->durations);
    }

    /**
     * A whole map, which is PHP that reading runs.
     *
     * @param non-empty-string $file
     */
    private static function unserialized(string $file): self|CannotJudge
    {
        try {
            $coverage = new Unserializer()->unserialize($file);
        } catch (CoverageFailure $failure) {
            $why = $failure->getMessage();

            return CannotJudge::because(sprintf('%s cannot be read as a coverage map: %s', $file, $why));
        }

        return new self(
            self::linesOf($coverage['basePath'], $coverage['codeCoverage']),
            array_map(static fn(array $result): float => $result['time'], $coverage['testResults']),
        );
    }

    /**
     * @param array<int, array<int, string>> $lines
     */
    private function covered(CoverageMap $map, Path $file, array $lines): CoverageMap
    {
        foreach ($lines as $line => $tests) {
            foreach ($tests as $test) {
                $map = $map->covered($file, Line::of($line), TestId::of($test));
            }
        }

        return $map;
    }

    /** @return array<string, array<int, array<int, string>>> */
    private static function linesOf(string $basePath, ProcessedCodeCoverageData $data): array
    {
        $ids = $data->testIds();
        $lines = [];

        foreach ($data->lineCoverage() as $file => $covered) {
            $path = sprintf('%s/%s', rtrim($basePath, '/'), $file);

            foreach ($covered as $line => $hits) {
                $lines[$path][$line] = array_intersect_key($ids, $hits ?? []);
            }
        }

        return $lines;
    }
}
