<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function array_sum;
use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function rtrim;

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Exception as CoverageFailure;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

use function sprintf;

/**
 * A coverage map as `--coverage-php` writes it, read with
 * phpunit/php-code-coverage: which tests ran each line of each file, by the
 * file's path on disk, and how long each test took.
 */
final readonly class CoverageFile implements Covering
{
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
            return PhpReport::missingAt($file);
        }

        if (! PhpReport::isWhole(sprintf('%s', file_get_contents($file)))) {
            return PhpReport::cutOffAt($file);
        }

        return self::unserialized($file);
    }

    /** The map, with each file as the project spells it. */
    public function map(Project $project): CoverageMap
    {
        $covered = [];
        $timed = [];

        foreach ($this->lines as $file => $lines) {
            $path = $project->relative($file);

            foreach ($lines as $line => $tests) {
                $covered[] = CoveredLine::of($path, $line, ...$tests);
            }
        }

        foreach ($this->durations as $test => $seconds) {
            $timed[] = TimedTest::of($test, $seconds);
        }

        return CoverageMap::of(...$covered)->timedEach(...$timed);
    }

    public function testsCovering(DiskPath $file, Line $first, Line $last): TestIds
    {
        $lines = array_key_exists($file->value(), $this->lines) ? $this->lines[$file->value()] : [];
        $tests = [];

        foreach ($lines as $line => $covering) {
            $covered = $line >= $first->number() && $line <= $last->number();

            foreach ($covered ? $covering : [] as $test) {
                $tests[] = TestId::of($test);
            }
        }

        return TestIds::of(...$tests);
    }

    /** How long a test took, or no time where the map does not time it. */
    public function secondsOf(TestId $test): float
    {
        return array_key_exists($test->value(), $this->durations) ? $this->durations[$test->value()] : 0.0;
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
            return PhpReport::unreadableAt($file, $failure->getMessage());
        }

        return new self(
            self::linesOf($coverage['basePath'], $coverage['codeCoverage']),
            array_map(static fn(array $result): float => $result['time'], $coverage['testResults']),
        );
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
