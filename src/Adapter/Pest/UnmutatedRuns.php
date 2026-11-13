<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function array_values;
use function dirname;
use function file_put_contents;
use function getmypid;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PendingControls;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\TestEvents;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\Filter;

use function sprintf;
use function strval;

/**
 * Unmutated controls as Pest runs them (ADR-0008): exactly each
 * control's tests, a row of a data set alone, selected by Pest's filter of
 * their ids where it fits and by the tests that judge the request otherwise,
 * with its file served unmutated through Pest's override (see
 * ServedOriginal), under the request's memory cap and allowed its limit;
 * side by side in the places of the request's pool, none started once the
 * request's deadline has passed, each forked from a warm worker where the
 * shell forks the tail (see TailShell). Its tests' time is the time its
 * process took, and its peak the larger of what the launcher it starts
 * through measured (see PeakLauncher) and what its forked child held.
 *
 * @internal
 */
final readonly class UnmutatedRuns
{
    /** The file the runs are written beside: the unmutated copies and the memory cap. */
    private const string BESIDE = 'pest/controls/runs';

    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private bool $byIds = false,
    ) {
    }

    public function of(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        $ready = $this->ready($request);

        if ($ready instanceof CannotJudge) {
            return $ready;
        }

        [$scan, $beside, $launcher] = $ready;
        [$runs, $commands, $ranWith] = $this->prepared($controls, $request, $scan, dirname($beside), $launcher);
        $ends = $commands === []
            ? ProcessEnds::of()
            : $this->shell->sideBySide($this->slotsOf($request), $request->deadline(), ...array_values($commands));
        $runs = $commands === [] ? $runs : $this->read($runs, $ends, $commands, $ranWith);
        $scan->remove();

        return $runs;
    }

    /**
     * What each control finds, started now where the shell starts commands
     * and taken once the gate needs it, the memory cap the scan wrote removed
     * then; run to their ends at once where it does not.
     */
    public function started(MutationRequest $request, Controls $controls): PendingControls
    {
        $ready = $this->ready($request);

        if ($ready instanceof CannotJudge || ! $this->shell instanceof StartsCommands) {
            return PendingControls::ran($ready instanceof CannotJudge ? $ready : $this->of($request, $controls));
        }

        [$scan, $beside, $launcher] = $ready;
        [$runs, $commands, $ranWith] = $this->prepared($controls, $request, $scan, dirname($beside), $launcher);

        if ($commands === []) {
            $scan->remove();

            return PendingControls::ran($runs);
        }

        $started = $this->shell->started($this->slotsOf($request), $request->deadline(), ...array_values($commands));

        return PendingControls::taking(
            function () use ($runs, $started, $commands, $ranWith, $scan): ControlRuns {
                $read = $this->read($runs, $started->ends(), $commands, $ranWith);
                $scan->remove();

                return $read;
            },
            static function () use ($started, $scan): void {
                $started->stop();
                $scan->remove();
            },
        );
    }

    /**
     * The memory scan, the file the runs are written beside and the launcher
     * the controls start through; or why one cannot be.
     *
     * @return array{MemoryScan, string, string}|CannotJudge
     */
    private function ready(MutationRequest $request): array|CannotJudge
    {
        $beside = $this->project->fresh(self::BESIDE);

        if ($beside instanceof CannotJudge) {
            return $beside;
        }

        $launcher = $this->launcher();
        $scan = $launcher instanceof CannotJudge
            ? $launcher
            : MemoryScan::beside($this->project, $beside, $request->memory(), $this->files);

        return match (true) {
            $launcher instanceof CannotJudge => $launcher,
            $scan instanceof CannotJudge => $scan,
            default => [$scan, $beside, $launcher],
        };
    }

    /**
     * The places the controls run side by side in: the request's pool's, numbered after any a run beside them holds.
     */
    private function slotsOf(MutationRequest $request): WorkerSlots
    {
        return WorkerSlots::inPool($request->pool(), strval(getmypid()));
    }

    /**
     * Each control's command, started through the launcher, with the files it
     * is run with, by the control's key; and what each control found that
     * cannot run.
     *
     * @return array{ControlRuns, array<string, Command>, array<string, array{Control, ControlFiles}>}
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
        $ranWith = [];

        foreach ($controls as $at => $control) {
            $files = ControlFiles::at($this->project, $at, $control, $directory);

            if ($files instanceof CannotJudge) {
                $runs = $runs->with($control, ControlRun::unrun($files->why()));

                continue;
            }

            $command = $files->served()
                ->onto($scan->onto($this->judging($control, $request, $files->log(), $files->events())))
                ->with([GateVariable::Peak->value => $files->forked()])
                ->launchedBy($launcher, $files->peak());
            $commands[$control->key()] = $this->silenced($command, $control, $files->events());
            $ranWith[$control->key()] = [$control, $files];
        }

        return [$runs, $commands, $ranWith];
    }

    /**
     * The run of a control's tests, selected as its mutant's run selected
     * them: by its filter, or by the test files it loaded with no filter, or
     * every one, under the request's tests; or else exactly its tests, a row
     * of a data set alone, where Pest's filter can hold them, by their ids
     * named in a file under `pest.longFilter: set` where it cannot (ADR-0138),
     * and the tests that judge the request otherwise. Allowed its limit, logged to
     * a file, and, under a silence limit or a watch of its tests, writing
     * each event of its tests as it happens.
     */
    private function judging(Control $control, MutationRequest $request, string $log, string $events): Command
    {
        $by = $control->selected();
        $selection = Selection::exactly($control->tests(), Suite::none());
        $judgedBy = match (true) {
            $by instanceof Filter => $by,
            $by instanceof NotGiven && $selection->fits() => $selection->filter(),
            default => $request->judgedBy(),
        };
        $ids = $by instanceof NotGiven && ! $selection->fits() && $this->byIds
            ? IdsFile::written($control->tests(), sprintf('%s%s', $log, IdsFile::SUFFIX))
            : NotGiven::value();

        return Invocation::of($this->project)->judging(
            $by instanceof Paths ? $by : Paths::none(),
            $judgedBy,
            $request->withheld(),
            ...[
                ...PhpUnitOption::inSuites($request->narrowing()->suitesFor($judgedBy)),
                sprintf('%s=%s', PhpUnitOption::LogJunit->value, $log),
                ...$ids instanceof NotGiven ? [] : [sprintf('%s=%s', PhpUnitOption::TestIdFilterFile->value, $ids)],
                ...$this->silenceOf($control, $events) instanceof SilenceLimit
                    ? [sprintf('%s=%s', PhpUnitOption::LogEventsText->value, $events)]
                    : [],
            ],
        )->within($control->limit());
    }

    /**
     * A control's command, stopped where it writes no event of its tests for
     * its silence limit, as a part of a heavy mutant's run is, or where a
     * test of it runs past its own limit (ADR-0090).
     */
    private function silenced(Command $command, Control $control, string $events): Command
    {
        $silence = $this->silenceOf($control, $events);

        return $silence instanceof SilenceLimit ? $command->silencedAfter($silence) : $command;
    }

    /**
     * What stops a control's run before its limit, the events of its tests
     * written to this file: its silence limit, and each test's own; none
     * where only its limit does.
     */
    private function silenceOf(Control $control, string $events): SilenceLimit|NotGiven
    {
        return SilenceLimit::stopping($control->silence(), $control->watch(), $events, TestEvents::PhpUnitText);
    }

    /** The launcher each control starts through, written among the adapter's own files; or why it cannot be. */
    private function launcher(): string|CannotJudge
    {
        $launcher = $this->project->fresh(PeakLauncher::in(dirname(self::BESIDE)));

        return is_string($launcher) && file_put_contents($launcher, PeakLauncher::SCRIPT) === false
            ? PeakLauncher::unwritten($launcher)
            : $launcher;
    }

    /**
     * What each control found, as the processes run side by side ended, with
     * the peak its launcher wrote and the tests its log names failing.
     *
     * @param non-empty-array<string, Command>            $commands by each control's key
     * @param array<string, array{Control, ControlFiles}> $ranWith  each control and the files it ran with, by its key
     */
    private function read(ControlRuns $runs, ProcessEnds $ends, array $commands, array $ranWith): ControlRuns
    {
        $keys = array_keys($commands);

        foreach ($ends as $at => $ran) {
            [$control, $files] = $ranWith[$keys[$at]];
            $runs = $runs->with($control, $files->found($ran));
        }

        return $runs;
    }
}
