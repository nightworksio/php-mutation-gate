<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\PendingOutline;
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
 * builds of the report, reading it as data while the gate takes its
 * inventory; the gate decides from the
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
        private ConvertsPhpReports $engine,
        private RanReport $report,
        private PendingOutline $outline,
    ) {
    }

    /**
     * The conversion of the report a run left, the engine reading its outline
     * while the gate goes on; or why there is none, and the gate reads the
     * report itself.
     */
    public static function started(Adapters $adapters, CoverageRan $ran): self|NotWritten
    {
        $runner = $adapters->runner;
        $engine = $adapters->maps;
        $report = $runner instanceof LeavesPhpReport ? $runner->report($ran) : NotGiven::value();

        if (! $engine instanceof ConvertsPhpReports) {
            return NotWritten::because(self::NO_ENGINE);
        }

        if (! $report instanceof RanReport) {
            return NotWritten::because(self::NO_REPORT);
        }

        $outline = $engine->outlining($report);
        $none = $outline->noneAtOnce();

        return $none instanceof NotWritten ? $none : new self($adapters, $engine, $report, $outline);
    }

    /**
     * The map of the report, admitted as the suites admit it and keyed by
     * these entries, written at a path of the project; why the engine wrote
     * none, and the gate writes it; or why it cannot be.
     */
    public function written(
        SuiteCoverage $suites,
        CoverageEntries|CannotJudge|NotGiven $entries,
        Path $into,
        MeasuredAt|Unplaced $at,
    ): Written|NotWritten|CannotJudge {
        $outline = $this->outline->outline();
        $admission = $outline instanceof MapOutline ? $suites->admission($outline) : $outline;

        return match (true) {
            ! $outline instanceof MapOutline => $outline,
            $admission instanceof CannotJudge, $admission instanceof NotWritten => $admission,
            default => $this->moved($this->convert($this->conversion($outline, $admission, $at, $entries)), $into),
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

    /** The map the engine writes of the report as the gate decides it, or why it wrote none. */
    private function convert(Conversion $conversion): Written|NotWritten
    {
        return $this->engine->convert($this->report, $conversion, $this->converted());
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
    private function conversion(
        MapOutline $outline,
        Admission|NotGiven $admission,
        MeasuredAt|Unplaced $at,
        CoverageEntries|CannotJudge|NotGiven $entries,
    ): Conversion {
        $admitted = $admission instanceof Admission ? $admission->outlined($outline) : $outline;

        return Conversion::of($outline, $admission, $at, CoverageEntries::keysByOutline($entries, $admitted));
    }
}
