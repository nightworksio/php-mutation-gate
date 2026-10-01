<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_intersect_key;
use function array_values;
use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;

use function rtrim;

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Exception as CoverageFailure;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

use function sprintf;

/**
 * The map PHPUnit's `--coverage-php` wrote, read with php-code-coverage: the
 * tests that ran each line of each file, as paths of the project, and how
 * long each test took, as php-code-coverage timed it.
 */
final readonly class CoverageFile
{
    /** The map in a file, or why it cannot be read: reading runs it, so a map cut short is not read. */
    public static function read(Project $project, string $file): CoverageMap|CannotJudge
    {
        $text = is_file($file) ? file_get_contents($file) : false;

        return match (true) {
            $file === '' || ! is_string($text) => PhpReport::missingAt($file),
            ! PhpReport::isWhole($text) => PhpReport::cutOffAt($file),
            default => self::unserialized($project, $file),
        };
    }

    /** @param non-empty-string $file */
    private static function unserialized(Project $project, string $file): CoverageMap|CannotJudge
    {
        try {
            $coverage = new Unserializer()->unserialize($file);
        } catch (CoverageFailure $failure) {
            return PhpReport::unreadableAt($file, $failure->getMessage());
        }

        $timed = [];

        foreach ($coverage['testResults'] as $test => $result) {
            $timed[] = TimedTest::of($test, $result['time']);
        }

        return CoverageMap::of(...self::lines($project, $coverage['basePath'], $coverage['codeCoverage']))
            ->timedEach(...$timed);
    }

    /** @return list<CoveredLine> each line a test ran, with the tests that ran it */
    private static function lines(Project $project, string $base, ProcessedCodeCoverageData $data): array
    {
        $tests = $data->testIds();
        $lines = [];

        foreach ($data->lineCoverage() as $file => $covered) {
            $path = $project->relative(sprintf('%s/%s', rtrim($base, '/'), $file));

            foreach ($covered as $line => $ran) {
                $lines[] = CoveredLine::of($path, $line, ...array_values(array_intersect_key($tests, $ran ?? [])));
            }
        }

        return $lines;
    }
}
