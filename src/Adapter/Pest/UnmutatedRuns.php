<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\JUnitFailures;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function strval;

/**
 * Unmutated controls as Pest runs them (ADR-0008): exactly each
 * control's tests, a row of a data set alone, selected by Pest's filter of
 * their ids where it fits and by the tests that judge the request otherwise,
 * with its file served unmutated through Pest's override (see
 * ServedOriginal), under the request's memory cap and allowed its limit;
 * side by side in the places of the request's pool, none started once the
 * request's deadline has passed. Its tests' time is the time its process
 * took, and its peak what the launcher it starts through measured (see
 * PeakLauncher).
 *
 * @internal
 */
final readonly class UnmutatedRuns
{
    /** The file the runs are written beside: the unmutated copies and the memory cap. */
    private const string BESIDE = 'pest/controls/runs';

    /** Where the launcher of a control at a position writes its peak, among the adapter's own files. */
    private const string PEAK = 'pest/controls/peak-%d';

    /** Where a control at a position writes its JUnit log, which names the tests that failed in it. */
    private const string LOG = 'pest/controls/junit-%d.xml';

    /** Where a control at a position writes the events of its tests as they happen, where it has a silence limit. */
    private const string EVENTS = 'pest/controls/events-%d.txt';

    public function __construct(private Project $project, private Shell $shell, private CapFiles $files)
    {
    }

    public function of(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
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
            default => $this->controlled($controls, $request, $scan, $beside, $launcher),
        };
    }

    /** What each control found, started through the launcher, and the memory cap the scan wrote removed. */
    private function controlled(
        Controls $controls,
        MutationRequest $request,
        MemoryScan $scan,
        string $beside,
        string $launcher,
    ): ControlRuns {
        [$runs, $commands, $peaks] = $this->prepared($controls, $request, $scan, dirname($beside), $launcher);
        $runs = $commands === [] ? $runs : $this->ended($runs, $request, $commands, $peaks);
        $scan->remove();

        return $runs;
    }

    /**
     * Each control's command, started through the launcher, with the files its
     * peak and its JUnit log are written to, by the control's key; and what
     * each control found that cannot run.
     *
     * @return array{ControlRuns, array<string, Command>, array<string, array{Control, string, string}>}
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
            $files = $this->filesOf($at, $control, $directory);

            if ($files instanceof CannotJudge) {
                $runs = $runs->with($control, ControlRun::unrun($files->why()));

                continue;
            }

            [$served, $peak, $log, $events] = $files;
            $command = $served->onto($scan->onto($this->judging($control, $request, $log, $events)))
                ->launchedBy($launcher, $peak);
            $commands[$control->key()] = $this->silenced($command, $control, $events);
            $peaks[$control->key()] = [$control, $peak, $log];
        }

        return [$runs, $commands, $peaks];
    }

    /**
     * The files a control at a position is run with: its file served
     * unmutated, or as the mutant a part of a heavy mutant's run serves,
     * its peak, its JUnit log, and where it writes the events of its tests
     * as they happen; or why one cannot be.
     *
     * @return array{ServedOriginal, string, string, string}|CannotJudge
     */
    private function filesOf(int $at, Control $control, string $directory): array|CannotJudge
    {
        $text = $control->served();
        $served = $text instanceof Contents
            ? ServedOriginal::as($this->project, $directory, $control->file(), $text)
            : ServedOriginal::of($this->project, $directory, $control->file());
        $peak = $served instanceof CannotJudge ? $served : $this->project->fresh(sprintf(self::PEAK, $at));
        $log = $peak instanceof CannotJudge ? $peak : $this->project->fresh(sprintf(self::LOG, $at));
        $events = $log instanceof CannotJudge ? $log : $this->project->fresh(sprintf(self::EVENTS, $at));

        return match (true) {
            $served instanceof CannotJudge => $served,
            $peak instanceof CannotJudge => $peak,
            $log instanceof CannotJudge => $log,
            $events instanceof CannotJudge => $events,
            default => [$served, $peak, $log, $events],
        };
    }

    /**
     * The run of a control's tests, selected as its mutant's run selected
     * them: by its filter, or by the test files it loaded with no filter, or
     * every one, under the request's tests; or else exactly its tests, a row
     * of a data set alone, where Pest's filter can hold them, and the tests
     * that judge the request where it cannot. Allowed its limit, logged to
     * a file, and, under a silence limit, writing each event of its tests
     * as it happens.
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

        return Invocation::of($this->project)->judging(
            $by instanceof Paths ? $by : Paths::none(),
            $judgedBy,
            $request->withheld(),
            ...[
                ...PhpUnitOption::inSuites($request->narrowing()->suitesFor($judgedBy)),
                sprintf('%s=%s', PhpUnitOption::LogJunit->value, $log),
                ...$control->silence() instanceof Seconds
                    ? [sprintf('%s=%s', PhpUnitOption::LogEventsText->value, $events)]
                    : [],
            ],
        )->within($control->limit());
    }

    /**
     * A control's command, stopped where it writes no event of its tests for
     * its silence limit, where it has one: a part of a heavy mutant's run.
     */
    private function silenced(Command $command, Control $control, string $events): Command
    {
        $silence = $control->silence();

        return $silence instanceof Seconds ? $command->silencedAfter(SilenceLimit::of($silence, $events)) : $command;
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
     * What each control found, run side by side, with the peak its launcher
     * wrote and the tests its log names failing.
     *
     * @param non-empty-array<string, Command>               $commands by each control's key
     * @param array<string, array{Control, string, string}> $peaks    each control, its peak and its log, by its key
     */
    private function ended(ControlRuns $runs, MutationRequest $request, array $commands, array $peaks): ControlRuns
    {
        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));
        $keys = array_keys($commands);

        foreach ($this->shell->sideBySide($slots, $request->deadline(), ...$commands) as $at => $ran) {
            [$control, $peak, $log] = $peaks[$keys[$at]];
            $failing = JUnitFailures::of(is_file($log) ? file_get_contents($log) : false);
            $run = ControlRun::ofProcess($ran, $failing);
            $measured = is_file($peak)
                ? PeakLauncher::measured($run, sprintf('%s', file_get_contents($peak)), PHP_OS_FAMILY)
                : $run;
            $runs = $runs->with($control, $measured);
        }

        return $runs;
    }
}
