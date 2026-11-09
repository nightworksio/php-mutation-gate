<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function dirname;
use function file_put_contents;
use function in_array;
use function is_dir;
use function mkdir;

use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Coverage\PendingOutline;
use NightWorksIO\MutationGate\Core\Coverage\ReportedLines;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\ConvertsPhpReports;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\MapEngine;
use SebastianBergmann\CodeCoverage\Exception as CoverageFailure;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

use function sprintf;

/**
 * An engine that vouches for the texts it was told to and splits nothing, saying why, and remembers each
 * text it was asked to vouch for and each split it was asked for; and that reads a run's report with
 * php-code-coverage, which runs it, into the map and outline the gate builds of it, writing that map into a
 * project at its root.
 */
final class MapEngineFake implements ConvertsPhpReports, MapEngine
{
    private const string DECLINED = 'The fake engine splits nothing.';

    /** @var list<string> */
    public private(set) array $asked = [];

    /** @var list<array{Path, MapSlices}> */
    public private(set) array $splits = [];

    /** @param list<string> $vouchedFor the texts it vouches for */
    public function __construct(private readonly array $vouchedFor = [], private readonly string $root = '')
    {
    }

    public function vouches(Contents $text): MapVouch
    {
        $this->asked[] = $text->text();

        return in_array($text->text(), $this->vouchedFor, strict: true) ? MapVouch::Vouched : MapVouch::Unvouched;
    }

    public function split(Path $whole, MapSlices $slices): NotWritten
    {
        $this->splits[] = [$whole, $slices];

        return NotWritten::because(self::DECLINED);
    }

    public function outlining(RanReport $report): PendingOutline
    {
        $map = $this->mapOf($report);

        return PendingOutline::read($map instanceof CoverageMap ? MapOutline::of($map) : $map);
    }

    public function convert(RanReport $report, Conversion $conversion, Path $into): Written|NotWritten
    {
        $map = $this->mapOf($report);
        $admission = $conversion->admission();
        $file = sprintf('%s/%s', $this->root, $into->value());

        if (! $map instanceof CoverageMap) {
            return $map;
        }

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), recursive: true);
        }

        file_put_contents($file, CoverageMapFile::encode(
            MapText::of($admission instanceof Admission ? $admission->applied($map) : $map),
            $conversion->at(),
            $conversion->keys(),
        ));

        return Written::to($file);
    }

    /** The map the gate builds of a report, read by running it. */
    private function mapOf(RanReport $report): CoverageMap|NotWritten
    {
        try {
            $coverage = new Unserializer()->unserialize($report->file() === '' ? '.' : $report->file());
        } catch (CoverageFailure $failure) {
            return NotWritten::because($failure->getMessage());
        }

        $timed = [];

        foreach ($coverage['testResults'] as $test => $result) {
            $timed[] = TimedTest::of($test, $result['time']);
        }

        $data = $coverage['codeCoverage'];

        return $report->recorded()->onto(
            ReportedLines::of($coverage['basePath'], $data->testIds(), $data->lineCoverage())
                ->map($report->relative(...), ...$timed),
        );
    }
}
