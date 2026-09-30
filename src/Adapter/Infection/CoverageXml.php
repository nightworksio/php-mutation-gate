<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use DOMDocument;
use DOMElement;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rtrim;
use function sprintf;

/**
 * A coverage directory in the layout Infection's `--coverage` reads: PHPUnit's
 * XML coverage, whose `covered by` entries say which tests run which line, and
 * its JUnit log, which says how long each test took.
 */
final readonly class CoverageXml
{
    private const string INDEX = 'index.xml';

    private const string UNREADABLE
        = '%s is not there or is not PHPUnit XML coverage, so the gate cannot say which tests run which line.';

    /** The map a coverage directory holds, with each file as the project spells it. */
    public static function read(Project $project, string $directory): CoverageMap|CannotJudge
    {
        $xml = sprintf('%s/%s', $directory, Invocation::XML);
        $index = self::loaded(sprintf('%s/%s', $xml, self::INDEX));
        $junit = JUnit::at(sprintf('%s/%s', $directory, Invocation::JUNIT));
        $map = $index instanceof CannotJudge ? $index : self::covered($project, $index, $xml);

        return match (true) {
            $map instanceof CannotJudge => $map,
            $junit instanceof CannotJudge => $junit,
            default => self::timed($map, $junit),
        };
    }

    private static function covered(Project $project, DOMDocument $index, string $xml): CoverageMap|CannotJudge
    {
        $map = CoverageMap::empty();
        $source = '';

        foreach ($index->getElementsByTagName('project') as $root) {
            $source = $root->getAttribute('source');
        }

        foreach ($index->getElementsByTagName('file') as $file) {
            $report = self::loaded(sprintf('%s/%s', $xml, $file->getAttribute('href')));

            if ($report instanceof CannotJudge) {
                return $report;
            }

            $map = self::linesOf($project, $report, $source, $map);
        }

        return $map;
    }

    /** An XML file, or why the coverage cannot be read where it is not there or not XML. */
    private static function loaded(string $file): DOMDocument|CannotJudge
    {
        return XmlFile::read($file, CannotJudge::because(sprintf(self::UNREADABLE, $file)));
    }

    private static function linesOf(
        Project $project,
        DOMDocument $report,
        string $source,
        CoverageMap $map,
    ): CoverageMap {
        foreach ($report->getElementsByTagName('file') as $file) {
            $path = $project->relative(self::onDisk($source, $file));

            foreach ($file->getElementsByTagName('line') as $line) {
                $map = self::coveredLine($map, $path, $line);
            }
        }

        return $map;
    }

    private static function coveredLine(CoverageMap $map, Path $path, DOMElement $line): CoverageMap
    {
        foreach ($line->getElementsByTagName('covered') as $covered) {
            $number = Line::of((int) $line->getAttribute('nr'));
            $map = $map->covered($path, $number, TestId::of($covered->getAttribute('by')));
        }

        return $map;
    }

    /** A covered file's path on disk: the report's source directory, the file's directory under it, and its name. */
    private static function onDisk(string $source, DOMElement $file): string
    {
        $directory = rtrim(sprintf('%s%s', $source, $file->getAttribute('path')), '/');

        return sprintf('%s/%s', $directory, $file->getAttribute('name'));
    }

    private static function timed(CoverageMap $map, JUnit $junit): CoverageMap
    {
        foreach ($junit->tests() as $test => $seconds) {
            $map = $map->timed(TestId::of($test), Seconds::of($seconds));
        }

        return $map;
    }
}
