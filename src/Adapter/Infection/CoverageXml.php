<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use DOMDocument;
use DOMElement;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A coverage directory in the layout Infection's `--coverage` reads: PHPUnit's
 * XML coverage, whose `covered by` entries say which tests run which line, and
 * its JUnit log, which says how long each test took.
 */
final readonly class CoverageXml
{
    /** The index of every file's report, in the directory of PHPUnit's XML coverage. */
    public const string INDEX = 'index.xml';

    private const string UNREADABLE
        = '%s is not there or is not PHPUnit XML coverage, so the gate cannot say which tests run which line.';

    /** The map a coverage directory holds, with each file as the project spells it. */
    public static function read(Project $project, DiskPath $directory): CoverageMap|CannotJudge
    {
        return self::over(CoverageMap::empty(), $project, $directory);
    }

    /**
     * The map a coverage directory the gate's own coverage run wrote holds,
     * with the lines its report beside the XML says no test ran.
     */
    public static function measured(Project $project, DiskPath $directory): CoverageMap|CannotJudge
    {
        $missed = MissedLines::in($project, $directory);

        return $missed instanceof CannotJudge ? $missed : self::over(CoverageMap::of(...$missed), $project, $directory);
    }

    /** Where a coverage directory's index of every file's report is. */
    public static function indexIn(DiskPath $directory): DiskPath
    {
        return $directory->child(Invocation::XML)->child(self::INDEX);
    }

    /** The lines and times a coverage directory holds, onto a map that holds the lines no test ran. */
    private static function over(CoverageMap $missed, Project $project, DiskPath $directory): CoverageMap|CannotJudge
    {
        $xml = $directory->child(Invocation::XML);
        $index = self::loaded(self::indexIn($directory));
        $junit = JUnit::at($directory->child(Invocation::JUNIT));
        $map = $index instanceof CannotJudge ? $index : self::covered($project, $index, $xml, $missed);

        return match (true) {
            $map instanceof CannotJudge => $map,
            $junit instanceof CannotJudge => $junit,
            default => self::timed($map, $junit),
        };
    }

    private static function covered(
        Project $project,
        DOMDocument $index,
        DiskPath $xml,
        CoverageMap $map,
    ): CoverageMap|CannotJudge {
        $source = '';

        foreach ($index->getElementsByTagName('project') as $root) {
            $source = $root->getAttribute('source');
        }

        foreach ($index->getElementsByTagName('file') as $file) {
            $report = self::loaded($xml->child($file->getAttribute('href')));

            if ($report instanceof CannotJudge) {
                return $report;
            }

            $map = self::linesOf($project, $report, $source, $map);
        }

        return $map;
    }

    /** An XML file, or why the coverage cannot be read where it is not there or not XML. */
    private static function loaded(DiskPath $file): DOMDocument|CannotJudge
    {
        return XmlFile::read($file->value(), CannotJudge::because(sprintf(self::UNREADABLE, $file->value())));
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

            $map = $map->executing($path, ...self::executedIn($file));
        }

        return $map;
    }

    /**
     * The methods of a file's report some test ran, as Infection reads them:
     * the methods of its classes or, where its classes have none, of its
     * traits, less each whose coverage is under one percent.
     *
     * @return list<ExecutedMethod>
     */
    private static function executedIn(DOMElement $file): array
    {
        $methods = self::methodsUnder($file, 'class');
        $methods = $methods === [] ? self::methodsUnder($file, 'trait') : $methods;
        $executed = [];

        foreach ($methods as $method) {
            $executed = (int) $method->getAttribute('coverage') === 0 ? $executed : [...$executed, ExecutedMethod::of(
                $method->getAttribute('name'),
                (int) $method->getAttribute('start'),
                (int) $method->getAttribute('end'),
            )];
        }

        return $executed;
    }

    /** @return list<DOMElement> the methods of a file's classes, or of its traits */
    private static function methodsUnder(DOMElement $file, string $kind): array
    {
        $methods = [];

        foreach ($file->getElementsByTagName('method') as $method) {
            $unit = $method->parentNode;
            $methods = $unit instanceof DOMElement && $unit->localName === $kind && $unit->parentNode === $file
                ? [...$methods, $method]
                : $methods;
        }

        return $methods;
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
        return DiskPath::of(sprintf('%s%s', $source, $file->getAttribute('path')))
            ->child($file->getAttribute('name'))
            ->value();
    }

    private static function timed(CoverageMap $map, JUnit $junit): CoverageMap
    {
        foreach ($junit->tests() as $test => $seconds) {
            $map = $map->timed(TestId::of($test), Seconds::of($seconds));
        }

        return $map;
    }
}
