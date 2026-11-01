<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_merge;
use function array_values;

use Closure;
use DOMDocument;
use DOMElement;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Xml;
use NightWorksIO\MutationGate\Core\NotGiven;

use function rtrim;
use function sprintf;

/**
 * PHPUnit's XML coverage, as PHPUnit and Testo write it into a directory:
 * an index of every file's report, and each report's `covered by` entries,
 * which say which tests run which line. It holds no time, so the map it
 * reads times no test. The adapter that ran the framework reads its files.
 */
final readonly class PhpUnitXml
{
    /** The index of every file's report, in the directory of the XML coverage. */
    public const string INDEX = 'index.xml';

    private const string UNREADABLE
        = '%s is not there or is not PHPUnit XML coverage, so the gate cannot say which tests run which line.';

    /** What parts the report's source directory from a file's directory under it. */
    private const string SEPARATOR = '/';

    /**
     * The map the XML coverage in this directory holds, with each file as the
     * project's root spells it, and with these lines, which no test ran.
     *
     * @param Closure(DiskPath): (string|false) $text what a file of the coverage holds; false where it is not there
     */
    public static function read(
        Root $project,
        DiskPath $directory,
        Closure $text,
        CoveredLine ...$missed,
    ): CoverageMap|CannotJudge {
        $index = self::loaded($directory->child(self::INDEX), $text);

        return $index instanceof CannotJudge
            ? $index
            : self::covered($project, $index, $directory, $text, array_values($missed));
    }

    /**
     * @param Closure(DiskPath): (string|false) $text
     * @param list<CoveredLine>                 $missed
     */
    private static function covered(
        Root $project,
        DOMDocument $index,
        DiskPath $directory,
        Closure $text,
        array $missed,
    ): CoverageMap|CannotJudge {
        $source = '';
        $lines = [$missed];
        $methods = [];

        foreach ($index->getElementsByTagName('project') as $root) {
            $source = $root->getAttribute('source');
        }

        foreach ($index->getElementsByTagName('file') as $file) {
            $report = self::loaded($directory->child($file->getAttribute('href')), $text);

            if ($report instanceof CannotJudge) {
                return $report;
            }

            foreach ($report->getElementsByTagName('file') as $covered) {
                $path = $project->relative(self::onDisk($source, $covered));
                $lines[] = self::linesOf($path, $covered);
                $methods[] = [$path, self::executedIn($covered)];
            }
        }

        $map = CoverageMap::of(...array_merge(...$lines));

        foreach ($methods as [$path, $executed]) {
            $map = $map->executing($path, ...$executed);
        }

        return $map;
    }

    /**
     * An XML file, or why the coverage cannot be read where it is not there or not XML.
     *
     * @param Closure(DiskPath): (string|false) $text
     */
    private static function loaded(DiskPath $file, Closure $text): DOMDocument|CannotJudge
    {
        $document = Xml::document($text($file));

        return $document instanceof NotGiven
            ? CannotJudge::because(sprintf(self::UNREADABLE, $file->value()))
            : $document;
    }

    /**
     * Each line of a file's report some test ran, with the tests that ran it.
     *
     * @return list<CoveredLine>
     */
    private static function linesOf(Path $path, DOMElement $file): array
    {
        $lines = [];

        foreach ($file->getElementsByTagName('line') as $line) {
            $tests = [];

            foreach ($line->getElementsByTagName('covered') as $covered) {
                $tests[] = $covered->getAttribute('by');
            }

            if ($tests !== []) {
                $lines[] = CoveredLine::of($path, (int) $line->getAttribute('nr'), ...$tests);
            }
        }

        return $lines;
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

    /**
     * A covered file's path on disk: the report's source directory, the
     * file's directory under it, and its name. PHPUnit starts the file's
     * directory with the separator, and Testo does not.
     */
    private static function onDisk(string $source, DOMElement $file): string
    {
        $directory = $file->getAttribute('path');
        $joined = $directory === '' || $directory[0] === self::SEPARATOR
            ? sprintf('%s%s', $source, $directory)
            : sprintf('%s%s%s', rtrim($source, self::SEPARATOR), self::SEPARATOR, $directory);

        return DiskPath::of($joined)->child($file->getAttribute('name'))->value();
    }
}
