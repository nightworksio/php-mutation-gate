<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

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
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\JUnitFailures;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function strval;

/**
 * Unmutated controls as the Infection runner runs them (ADR-0008): each
 * control's tests with every test they depend on, in a suite of the files
 * that declare their classes and selected by their ids, on a config shaped
 * as Infection shapes a mutant's, whose bootstrap serves an unchanged copy
 * of its file through Infection's include interceptor (see StartUpConfig),
 * under the request's memory cap and allowed its limit; side by side in
 * the request's pool, none started once the request's deadline has passed.
 * Its tests' time is the time its process took, and its peak what the
 * launcher it starts through measured (see PeakLauncher).
 *
 * @internal
 */
final readonly class UnmutatedRuns
{
    /** The place of a control's files, in the controls' directory, by its position. */
    private const string PLACE = '%s/control-%d';

    /** Where the launcher of a control at a position writes its peak, in the controls' directory. */
    private const string PEAK = '%s/control-%d/peak';

    /** Where the control at a position writes its JUnit log, which names the tests that failed in it. */
    private const string LOG = '%s/control-%d/junit.xml';

    /** Where the control at a position writes the events of its tests as they happen, where it has a silence limit. */
    private const string EVENTS = '%s/control-%d/events.txt';

    public function __construct(private Project $project, private Shell $shell, private CapFiles $files)
    {
    }

    public function of(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        $config = OwnConfig::in($this->project);
        $scan = $config instanceof CannotJudge
            ? $config
            : MemoryScan::in($this->project, $request->memory(), $this->files);
        $launcher = $scan instanceof CannotJudge ? $scan : $this->launcher();

        return match (true) {
            $config instanceof CannotJudge => $config,
            $scan instanceof CannotJudge => $scan,
            $launcher instanceof CannotJudge => $launcher,
            default => $this->controlled($request, $controls, $config, $scan, $launcher),
        };
    }

    /** The launcher each control starts through, written among the adapter's own files; or why it cannot be. */
    private function launcher(): string|CannotJudge
    {
        $launcher = $this->project->fresh(PeakLauncher::in($this->project->own(Control::DIRECTORY)));

        return is_string($launcher) && file_put_contents($launcher, PeakLauncher::SCRIPT) === false
            ? PeakLauncher::unwritten($launcher)
            : $launcher;
    }

    /**
     * What each control found, run side by side in the request's pool, none
     * started once the request's deadline has passed.
     */
    private function controlled(
        MutationRequest $request,
        Controls $controls,
        OwnConfig $config,
        MemoryScan $scan,
        string $launcher,
    ): ControlRuns {
        $runs = ControlRuns::none();
        $asked = [];
        $commands = [];

        foreach ($controls as $at => $control) {
            $command = $this->commandOf($at, $control, $request, $config, $scan, $launcher);

            if ($command instanceof CannotJudge) {
                $runs = $runs->with($control, ControlRun::unrun($command->why()));

                continue;
            }

            $asked[] = [$control, $this->placed(self::PEAK, $at), $this->placed(self::LOG, $at)];
            $commands[] = $command;
        }

        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));

        foreach ($this->shell->sideBySide($slots, $request->deadline(), ...$commands) as $index => $ran) {
            [$control, $peak, $log] = $asked[$index];
            $failing = JUnitFailures::of(is_file($log) ? file_get_contents($log) : false);
            $run = ControlRun::ofProcess($ran, $failing);
            $measured = is_file($peak)
                ? PeakLauncher::measured($run, sprintf('%s', file_get_contents($peak)), PHP_OS_FAMILY)
                : $run;
            $runs = $runs->with($control, $measured);
        }

        $scan->remove();

        return $runs;
    }

    /**
     * A control's command: its tests, with every test they depend on, on a
     * config of their own, through the launcher, writing its peak and its
     * JUnit log beside it; or why it cannot run.
     */
    private function commandOf(
        int $at,
        Control $control,
        MutationRequest $request,
        OwnConfig $own,
        MemoryScan $scan,
        string $launcher,
    ): Command|CannotJudge {
        $tests = TestFiles::withDependencies($this->project, $control->tests());
        $written = StartUpConfig::holding(
            $this->project,
            $own,
            $control->file(),
            TestFiles::declaring($this->project, $tests)->files(),
            sprintf(self::PLACE, Control::DIRECTORY, $at),
            $control->served(),
        );
        $peak = $written instanceof CannotJudge ? $written : $this->project->fresh($this->placed(self::PEAK, $at));
        $log = $peak instanceof CannotJudge ? $peak : $this->project->fresh($this->placed(self::LOG, $at));
        $events = $log instanceof CannotJudge ? $log : $this->project->fresh($this->placed(self::EVENTS, $at));

        return match (true) {
            $written instanceof CannotJudge => $written,
            $peak instanceof CannotJudge => $peak,
            $log instanceof CannotJudge => $log,
            $events instanceof CannotJudge => $events,
            default => $this->silenced(
                $scan->onto(
                    Invocation::controlling(
                        $this->project,
                        $own,
                        $written,
                        $tests,
                        $log,
                        $this->eventsOf($control, $events),
                    )
                        ->withholding($request->withheld())
                        ->within($control->limit())
                        ->launchedBy($launcher, $peak),
                ),
                $control,
                $events,
            ),
        };
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

    /** Where a control writes the events of its tests as they happen: there where it has a silence limit, else none. */
    private function eventsOf(Control $control, string $events): string|NotGiven
    {
        return $control->silence() instanceof Seconds ? $events : NotGiven::value();
    }

    /** Where the control at a position writes one of its files: its launcher's peak, its JUnit log, or its events. */
    private function placed(string $file, int $at): string
    {
        return $this->project->own(sprintf($file, Control::DIRECTORY, $at));
    }
}
