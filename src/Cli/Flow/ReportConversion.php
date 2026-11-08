<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\ConvertsPhpReports;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\LeavesPhpReport;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The map of the PHP report a run the project started in this job left,
 * written by the map engine (ADR-0119): the engine outlines the map the gate
 * builds of the report, reading it as data; the gate decides from the
 * outline what the suites admit, whether each hold runs what it holds, and
 * the entries' keys; and the engine writes the map as the gate writes it,
 * into the gate's workspace, from where the gate writes it where it was asked
 * as it writes its own. Where the runner leaves no PHP report, the engine
 * converts none, or it declines, the gate reads the report itself.
 *
 * @internal
 */
final readonly class ReportConversion
{
    private const string NO_ENGINE = 'The map engine converts no PHP report.';

    private const string NO_REPORT = 'The runner leaves no PHP report for an engine to read.';

    private const string UNREAD = 'The gate cannot read the map the engine wrote.';

    private function __construct(
        private Adapters $adapters,
        private ConvertsPhpReports|NotGiven $engine,
        private SuiteCoverage $suites,
        private CoverageEntries|CannotJudge|NotGiven $entries,
    ) {
    }

    public static function of(
        Adapters $adapters,
        SuiteCoverage $suites,
        CoverageEntries|CannotJudge|NotGiven $entries,
    ): self {
        $maps = $adapters->maps;

        return new self($adapters, $maps instanceof ConvertsPhpReports ? $maps : NotGiven::value(), $suites, $entries);
    }

    /**
     * The map of the report a run left, written at a path of the project; why
     * the engine wrote none, and the gate writes it; or why it cannot be.
     */
    public function written(CoverageRan $ran, Path $into, MeasuredAt|Unplaced $at): Written|NotWritten|CannotJudge
    {
        $runner = $this->adapters->runner;
        $engine = $this->engine;
        $report = $runner instanceof LeavesPhpReport ? $runner->report($ran) : NotGiven::value();

        if (! $engine instanceof ConvertsPhpReports) {
            return NotWritten::because(self::NO_ENGINE);
        }

        if (! $report instanceof RanReport) {
            return NotWritten::because(self::NO_REPORT);
        }

        $outline = $engine->outline($report);
        $admission = $outline instanceof MapOutline ? $this->suites->admission($outline) : $outline;

        return match (true) {
            ! $outline instanceof MapOutline => $outline,
            $admission instanceof CannotJudge, $admission instanceof NotWritten => $admission,
            default => $this->moved(
                $engine->convert($report, $this->conversion($outline, $admission, $at), $this->converted()),
                $into,
            ),
        };
    }

    /**
     * The map the engine wrote, written at a path of the project as the gate
     * writes its own; or why the engine wrote none, or the gate cannot read
     * it, and the gate writes the map itself.
     */
    private function moved(Written|NotWritten $converted, Path $into): Written|NotWritten|CannotJudge
    {
        $project = $this->adapters->project;
        $map = $converted instanceof Written ? $project->read($this->converted()) : $converted;
        $written = match (true) {
            $map instanceof Contents => $project->write($into, $map),
            $map instanceof NotWritten => $map,
            default => NotWritten::because(self::UNREAD),
        };
        $project->remove($this->converted());

        return $written;
    }

    /** Where the engine writes the map. */
    private function converted(): Path
    {
        return CoverageMapFile::in(Workspace::convertedCoverage());
    }

    /**
     * What the gate decides of the map: its admission, where it was
     * measured, and its entries' keys by the admitted outline.
     */
    private function conversion(MapOutline $outline, Admission|NotGiven $admission, MeasuredAt|Unplaced $at): Conversion
    {
        $admitted = $admission instanceof Admission ? $admission->outlined($outline) : $outline;

        return Conversion::of($outline, $admission, $at, KeptCoverage::keysBy($this->entries, $admitted));
    }
}
