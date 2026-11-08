<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_sum;

use Closure;

use function file_get_contents;
use function filesize;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;
use NightWorksIO\MutationGate\Core\Coverage\ReportedLines;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use SebastianBergmann\CodeCoverage\Exception as CoverageFailure;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

use function sprintf;

/**
 * A coverage map as `--coverage-php` writes it, read with
 * phpunit/php-code-coverage: which tests ran each executable line of each
 * file, none for a line no test ran, by the file's path on disk, and how
 * long each test took.
 *
 * @internal
 */
final readonly class CoverageFile implements Covering
{
    /** @param array<string, float> $durations each test's seconds, by id */
    private function __construct(private ReportedLines $lines, private array $durations)
    {
    }

    /** @param non-empty-string $file */
    public static function at(string $file): self|CannotJudge
    {
        if (! is_file($file)) {
            return PhpReport::missingAt($file);
        }

        if (! PhpReport::isWhole((int) filesize($file), self::from($file))) {
            return PhpReport::cutOffAt($file);
        }

        return self::unserialized($file);
    }

    /** The map, with each file as the project spells it. */
    public function map(Project $project): CoverageMap
    {
        $timed = [];

        foreach ($this->durations as $test => $seconds) {
            $timed[] = TimedTest::of($test, $seconds);
        }

        return $this->lines->map($project->relative(...), ...$timed);
    }

    public function testsCovering(DiskPath $file, Line $first, Line $last): TestIds
    {
        return $this->lines->covering($file, $first, $last);
    }

    public function tests(): TestIds
    {
        return TestIds::of(...array_map(TestId::of(...), array_keys($this->durations)));
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
     * A file's bytes from a byte on.
     *
     * @return Closure(int): string
     */
    private static function from(string $file): Closure
    {
        return static fn(int $from): string => sprintf('%s', file_get_contents($file, offset: $from));
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

        $data = $coverage['codeCoverage'];

        return new self(
            ReportedLines::of($coverage['basePath'], $data->testIds(), $data->lineCoverage()),
            array_map(static fn(array $result): float => $result['time'], $coverage['testResults']),
        );
    }
}
