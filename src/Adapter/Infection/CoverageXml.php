<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\PhpUnitXml;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Root;

/**
 * A coverage directory in the layout Infection's `--coverage` reads: PHPUnit's
 * XML coverage, whose `covered by` entries say which tests run which line, and
 * its JUnit log, which says how long each test took.
 */
final readonly class CoverageXml
{
    /** The map a coverage directory holds, with each file as the project spells it. */
    public static function read(Project $project, DiskPath $directory): CoverageMap|CannotJudge
    {
        return self::over([], $project, $directory);
    }

    /**
     * The map a coverage directory the gate's own coverage run wrote holds,
     * with the lines its report beside the XML says no test ran.
     */
    public static function measured(Project $project, DiskPath $directory): CoverageMap|CannotJudge
    {
        $missed = MissedLines::in($project, $directory);

        return $missed instanceof CannotJudge ? $missed : self::over($missed, $project, $directory);
    }

    /** Where a coverage directory's index of every file's report is. */
    public static function indexIn(DiskPath $directory): DiskPath
    {
        return $directory->child(Invocation::XML)->child(PhpUnitXml::INDEX);
    }

    /**
     * The lines and times a coverage directory holds, with the lines no test
     * ran, built into a map at once.
     *
     * @param list<CoveredLine> $missed
     */
    private static function over(array $missed, Project $project, DiskPath $directory): CoverageMap|CannotJudge
    {
        $map = PhpUnitXml::read(
            Root::of($project->root()),
            $directory->child(Invocation::XML),
            static fn(DiskPath $file): string|false => is_file($file->value())
                ? file_get_contents($file->value())
                : false,
            ...$missed,
        );
        $junit = JUnit::at($directory->child(Invocation::JUNIT));

        return match (true) {
            $map instanceof CannotJudge => $map,
            $junit instanceof CannotJudge => $junit,
            default => self::timed($map, $junit),
        };
    }

    private static function timed(CoverageMap $map, JUnit $junit): CoverageMap
    {
        $timed = [];

        foreach ($junit->tests() as $test => $seconds) {
            $timed[] = TimedTest::of($test, $seconds);
        }

        return $map->timedEach(...$timed);
    }
}
