<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_diff;
use function array_keys;
use function array_values;
use function basename;
use function count;
use function dirname;

use DOMDocument;
use DOMElement;

use function explode;
use function file_put_contents;
use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethods;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function preg_match;
use function sprintf;

/**
 * A coverage map the gate handed on from another job, written back into the
 * layout Infection's `--coverage` reads: PHPUnit's XML coverage, each file's
 * report with the lines its tests ran and the methods they ran, and a JUnit
 * log of each test class, with its file and the time its tests took.
 */
final readonly class CoverageLayout
{
    /** The namespace of PHPUnit's XML coverage. */
    private const string COVERAGE = 'https://schema.phpunit.de/coverage/1.0';

    /** A report's share of lines run, where it records any. */
    private const string WHOLE = '100';

    /** How a coverage id names a data set's test: its method, and the data set's number or name. */
    private const string DATA_SET = '/^(?<method>[^#]+)#(?<set>.*)$/sD';

    /** A data set's number. */
    private const string NUMBER = '/^\d+$/D';

    private const string NO_TEST_FILE
        = 'The coverage map names the test class %s, and no test file declares it, so Infection cannot run its tests.';

    /** The map, written into a directory, which it answers; or why it cannot be. */
    public static function write(Project $project, CoverageMap $map, string $directory): string|CannotJudge
    {
        $classes = self::classesOf($map);
        $files = TestFiles::byClass($project, $classes);
        $unfound = array_values(array_diff($classes, array_keys($files)));

        if ($unfound !== []) {
            return CannotJudge::because(sprintf(self::NO_TEST_FILE, $unfound[0]));
        }

        $documents = [
            sprintf('%s/%s', Invocation::XML, CoverageXml::INDEX) => self::index($project, $map),
            Invocation::JUNIT => self::junit($project, $map, $files),
        ];

        foreach ($map->files() as $file) {
            $documents[sprintf('%s/%s.xml', Invocation::XML, $file->value())] = self::report($map, $file);
        }

        return self::written($project, $directory, $documents);
    }

    /**
     * The directory, once each document is written into it with no earlier
     * run's copy left, or why an earlier copy is still there.
     *
     * @param array<string, DOMDocument> $documents each document, by its path in the directory
     */
    private static function written(Project $project, string $directory, array $documents): string|CannotJudge
    {
        foreach ($documents as $path => $document) {
            $file = $project->fresh(sprintf('%s/%s', $directory, $path));

            if ($file instanceof CannotJudge) {
                return $file;
            }

            file_put_contents($file, $document->saveXML());
        }

        return $directory;
    }

    /**
     * The index of every file's report. Infection refuses an index whose
     * project ran no line at all; the map comes from a run of the whole suite
     * and holds the files this run mutates, so the index says at least one.
     */
    private static function index(Project $project, CoverageMap $map): DOMDocument
    {
        [$document, $root] = self::document();
        $source = self::element($root, 'project', ['source' => $project->root()]);
        $directory = self::element($source, 'directory', ['name' => '/']);
        $totals = self::element($directory, 'totals', []);
        $ran = 0;

        foreach ($map->files() as $file) {
            $ran += count($map->linesCovered($file));
            self::element($directory, 'file', [
                'name' => basename($file->value()),
                'href' => sprintf('%s.xml', $file->value()),
            ]);
        }

        self::element($totals, 'lines', ['executed' => sprintf('%d', max($ran, 1))]);

        return $document;
    }

    /** One file's report: the lines its tests ran and by which, and the methods they ran. */
    private static function report(CoverageMap $map, Path $file): DOMDocument
    {
        [$document, $root] = self::document();
        $directory = dirname($file->value());
        $report = self::element($root, 'file', [
            'name' => basename($file->value()),
            'path' => sprintf('/%s', $directory === '.' ? '' : $directory),
        ]);
        $lines = $map->linesCovered($file);
        $totals = self::element($report, 'totals', []);
        self::element($totals, 'lines', [
            'executed' => sprintf('%d', count($lines)),
            'percent' => self::WHOLE,
        ]);
        $unit = self::element($report, 'class', []);

        foreach ($map->methods()->at($file, ExecutedMethods::none()) as $method) {
            self::element($unit, 'method', [
                'name' => $method->name(),
                'start' => sprintf('%d', $method->first()->number()),
                'end' => sprintf('%d', $method->last()->number()),
                'coverage' => self::WHOLE,
            ]);
        }

        $coverage = self::element($report, 'coverage', []);

        foreach ($lines as $line) {
            $covered = self::element($coverage, 'line', ['nr' => sprintf('%d', $line->number())]);

            foreach ($map->testsCovering($file, $line) as $test) {
                self::element($covered, 'covered', ['by' => $test->value()]);
            }
        }

        return $document;
    }

    /**
     * The JUnit log: a suite for each test class, with its file and the time
     * its tests took, and each test as PHPUnit names it there.
     *
     * @param array<string, Path> $files each test class's file, by the class
     */
    private static function junit(Project $project, CoverageMap $map, array $files): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = new DOMElement('testsuites');
        $document->appendChild($root);
        $suites = [];

        foreach ($files as $class => $file) {
            $suites[$class] = self::appended($root, new DOMElement('testsuite'), [
                'name' => $class,
                'file' => $project->absolute($file),
            ]);
        }

        foreach ($map->tests() as $test) {
            [$class, $method] = [...explode('::', $test->value(), 2), ''];
            self::appended($suites[$class], new DOMElement('testcase'), [
                'name' => self::loggedName($method),
                'class' => $class,
                'file' => $project->absolute($files[$class]),
                'time' => sprintf('%F', self::secondsOf($map, $test)),
            ]);
        }

        foreach ($suites as $suite) {
            $suite->setAttribute('time', sprintf('%F', self::timeOf($suite)));
        }

        return $document;
    }

    /** How long a suite's tests took together. */
    private static function timeOf(DOMElement $suite): float
    {
        $seconds = 0.0;

        foreach ($suite->getElementsByTagName('testcase') as $case) {
            $seconds += (float) $case->getAttribute('time');
        }

        return $seconds;
    }

    /** @return list<string> each test class the map's tests belong to, once, in their order */
    private static function classesOf(CoverageMap $map): array
    {
        $classes = [];

        foreach ($map->tests() as $test) {
            $classes[explode('::', $test->value(), 2)[0]] = true;
        }

        return array_keys($classes);
    }

    /** A test's name as PHPUnit logs it: a data set's test as `<method> with data set #<n>` or `… "<name>"`. */
    private static function loggedName(string $method): string
    {
        if (preg_match(self::DATA_SET, $method, $named) !== 1) {
            return $method;
        }

        return preg_match(self::NUMBER, $named['set']) === 1
            ? sprintf('%s with data set #%s', $named['method'], $named['set'])
            : sprintf('%s with data set "%s"', $named['method'], $named['set']);
    }

    private static function secondsOf(CoverageMap $map, TestId $test): float
    {
        $seconds = $map->durationOf($test);

        return $seconds instanceof Seconds ? $seconds->seconds() : 0.0;
    }

    /** @return array{DOMDocument, DOMElement} a coverage document and its root */
    private static function document(): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = new DOMElement('phpunit', namespace: self::COVERAGE);
        $document->appendChild($root);

        return [$document, $root];
    }

    /**
     * An element of PHPUnit's XML coverage, with these attributes, appended to another.
     *
     * @param array<string, string> $attributes
     */
    private static function element(DOMElement $parent, string $name, array $attributes): DOMElement
    {
        return self::appended($parent, new DOMElement($name, namespace: self::COVERAGE), $attributes);
    }

    /**
     * An element, appended to another, with these attributes.
     *
     * @param array<string, string> $attributes
     */
    private static function appended(DOMElement $parent, DOMElement $element, array $attributes): DOMElement
    {
        $parent->appendChild($element);

        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, $value);
        }

        return $element;
    }
}
