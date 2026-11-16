<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use Closure;

use function file_get_contents;
use function filesize;
use function is_file;
use function is_readable;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;
use NightWorksIO\MutationGate\Core\Coverage\ReportedLines;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use SebastianBergmann\CodeCoverage\Exception as CoverageFailure;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

use function sprintf;

/**
 * The map the framework wrote as php-code-coverage's PHP report, read with
 * php-code-coverage: the tests that ran each executable line of each file, as
 * paths of the project, none for a line no test ran, and how long each test
 * took, as php-code-coverage timed it.
 *
 * @internal
 */
final readonly class CoverageFile
{
    /** The map in a file, or why it cannot be read: reading runs it, so a map cut short is not read. */
    public static function read(Project $project, string $file): CoverageMap|CannotJudge
    {
        return self::isFile($file) ? self::readFrom($project, $file) : PhpReport::missingAt($file);
    }

    /** @param non-empty-string $file */
    private static function readFrom(Project $project, string $file): CoverageMap|CannotJudge
    {
        return match (true) {
            ! is_readable($file) => PhpReport::missingAt($file),
            ! PhpReport::isWhole((int) filesize($file), self::from($file)) => PhpReport::cutOffAt($file),
            default => self::unserialized($project, $file),
        };
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

    /** @phpstan-assert-if-true non-empty-string $file */
    private static function isFile(string $file): bool
    {
        return is_file($file);
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

        $data = $coverage['codeCoverage'];

        return ReportedLines::of($coverage['basePath'], $data->testIds(), $data->lineCoverage())
            ->map($project->relative(...), ...$timed);
    }
}
