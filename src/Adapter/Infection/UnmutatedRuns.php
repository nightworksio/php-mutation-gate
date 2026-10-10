<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

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
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;

use function sprintf;
use function strval;

/**
 * Unmutated controls as the Infection runner runs them (ADR-0008, decision
 * 2): each control's tests with every test they depend on, in a suite of
 * the files that declare their classes and selected by their ids, on a
 * config shaped as Infection shapes a mutant's, whose bootstrap serves an unchanged copy of its file through
 * Infection's include interceptor (see StartUpConfig), under the request's
 * memory cap and allowed its limit; side by side in the request's pool, none
 * started once the request's deadline has passed. Its
 * tests' time is the time its process took, and its peak what the launcher
 * it starts through measured (see PeakLauncher).
 */
final readonly class UnmutatedRuns
{
    /** The place of a control's files, in the controls' directory, by its position. */
    private const string PLACE = '%s/control-%d';

    /** Where the launcher of a control at a position writes its peak, in the controls' directory. */
    private const string PEAK = '%s/control-%d/peak';

    public function __construct(private Project $project, private Shell $shell, private CapFiles $files)
    {
    }

    public function of(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        $config = OwnConfig::in($this->project);
        $scan = $config instanceof CannotJudge
            ? $config
            : MemoryScan::in($this->project, $request->memory(), $this->files);
        $launcher = $scan instanceof CannotJudge
            ? $scan
            : $this->project->fresh(PeakLauncher::in($this->project->own(Control::DIRECTORY)));

        return match (true) {
            $config instanceof CannotJudge => $config,
            $scan instanceof CannotJudge => $scan,
            $launcher instanceof CannotJudge => $launcher,
            default => $this->controlled($request, $controls, $config, $scan, $launcher),
        };
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
        file_put_contents($launcher, PeakLauncher::SCRIPT);
        $runs = ControlRuns::none();
        $asked = [];
        $commands = [];

        foreach ($controls as $at => $control) {
            $command = $this->commandOf($at, $control, $request, $config, $scan, $launcher);

            if ($command instanceof CannotJudge) {
                $runs = $runs->with($control, ControlRun::unrun($command->why()));

                continue;
            }

            $asked[] = [$control, $this->peakOf($at)];
            $commands[] = $command;
        }

        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));

        foreach ($this->shell->sideBySide($slots, $request->deadline(), ...$commands) as $index => $ran) {
            [$control, $peak] = $asked[$index];
            $written = is_file($peak) ? (string) file_get_contents($peak) : '';
            $runs = $runs->with($control, PeakLauncher::measured(ControlRun::ofProcess($ran), $written, PHP_OS_FAMILY));
        }

        $scan->remove();

        return $runs;
    }

    /**
     * A control's command: its tests, with every test they depend on, on a
     * config of their own, through the launcher, writing its peak beside it; or why it cannot run.
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
        );
        $peak = $written instanceof CannotJudge ? $written : $this->project->fresh($this->peakOf($at));

        return match (true) {
            $written instanceof CannotJudge => $written,
            $peak instanceof CannotJudge => $peak,
            default => $scan->onto(
                Invocation::controlling($this->project, $own, $written, $tests)
                    ->withholding($request->withheld())
                    ->within($control->limit())
                    ->launchedBy($launcher, $peak),
            ),
        };
    }

    /** Where the launcher of the control at a position writes its peak. */
    private function peakOf(int $at): string
    {
        return $this->project->own(sprintf(self::PEAK, Control::DIRECTORY, $at));
    }
}
