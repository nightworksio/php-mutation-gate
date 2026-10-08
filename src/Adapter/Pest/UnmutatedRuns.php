<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function array_values;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;

use function sprintf;
use function strval;

/**
 * Unmutated controls as Pest runs them (ADR-0008, decision 2): each
 * control's tests, selected by Pest's filter of their ids where it fits and
 * by the tests that judge the request otherwise, as a mutant's own run
 * selects them, with its file served unmutated through Pest's override (see
 * ServedOriginal), under the request's memory cap and allowed its limit;
 * side by side in the places of the request's pool, none started once the
 * request's deadline has passed. Its tests' time is the time its process
 * took, and its peak what the launcher it starts through measured (see
 * PeakLauncher).
 */
final readonly class UnmutatedRuns
{
    /** The file the runs are written beside: the unmutated copies and the memory cap. */
    private const string BESIDE = 'pest/controls/runs';

    /** Where the launcher of a control at a position writes its peak, among the adapter's own files. */
    private const string PEAK = 'pest/controls/peak-%d';

    public function __construct(private Project $project, private Shell $shell, private CapFiles $files)
    {
    }

    public function of(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        $beside = $this->project->fresh(self::BESIDE);

        if ($beside instanceof CannotJudge) {
            return $beside;
        }

        $scan = MemoryScan::beside($this->project, $beside, $request->memory(), $this->files);

        if ($scan instanceof CannotJudge) {
            return $scan;
        }

        $launcher = PeakLauncher::in(dirname($beside));
        file_put_contents($launcher, PeakLauncher::SCRIPT);
        [$runs, $commands, $peaks] = $this->prepared($controls, $request, $scan, dirname($beside), $launcher);
        $runs = $commands === [] ? $runs : $this->ended($runs, $request, $commands, $peaks);
        $scan->remove();

        return $runs;
    }

    /**
     * Each control's command, started through the launcher, with the file its
     * peak is written to, by the control's key; and what each control found
     * that cannot run.
     *
     * @return array{ControlRuns, array<string, Command>, array<string, array{Control, string}>}
     */
    private function prepared(
        Controls $controls,
        MutationRequest $request,
        MemoryScan $scan,
        string $directory,
        string $launcher,
    ): array {
        $runs = ControlRuns::none();
        $commands = [];
        $peaks = [];

        foreach ($controls as $at => $control) {
            $served = ServedOriginal::of($this->project, $directory, $control->file());
            $peak = $served instanceof CannotJudge ? $served : $this->project->fresh(sprintf(self::PEAK, $at));

            if ($peak instanceof CannotJudge) {
                $runs = $runs->with($control, ControlRun::unrun($peak->why()));

                continue;
            }

            $commands[$control->key()] = $served->onto($scan->onto($this->judging($control, $request)))
                ->launchedBy($launcher, $peak);
            $peaks[$control->key()] = [$control, $peak];
        }

        return [$runs, $commands, $peaks];
    }

    /** The run of a control's tests, as a mutant's own run selects them, allowed its limit. */
    private function judging(Control $control, MutationRequest $request): Command
    {
        $selection = Selection::of($control->tests());

        return Invocation::installedIn($this->project->vendor())->judging(
            Paths::none(),
            $selection->fits() ? $selection->filter() : $request->judgedBy(),
            $request->withheld(),
        )->within($control->limit());
    }

    /**
     * What each control found, run side by side, with the peak its launcher
     * wrote.
     *
     * @param non-empty-array<string, Command>       $commands by each control's key
     * @param array<string, array{Control, string}> $peaks    each control and its peak's file, by its key
     */
    private function ended(ControlRuns $runs, MutationRequest $request, array $commands, array $peaks): ControlRuns
    {
        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));
        $keys = array_keys($commands);

        foreach ($this->shell->sideBySide($slots, $request->deadline(), ...array_values($commands)) as $at => $ran) {
            [$control, $peak] = $peaks[$keys[$at]];
            $written = is_file($peak) ? (string) file_get_contents($peak) : '';
            $runs = $runs->with($control, PeakLauncher::measured(ControlRun::ofProcess($ran), $written, PHP_OS_FAMILY));
        }

        return $runs;
    }
}
