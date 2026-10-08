<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;
use function file_put_contents;
use function hrtime;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * Unmutated controls as the Infection runner runs them (ADR-0008, decision
 * 2): each control's tests, in a suite of the files that declare their
 * classes and selected by their ids, on a config shaped as Infection shapes
 * a mutant's, whose bootstrap serves an unchanged copy of its file through
 * Infection's include interceptor (see StartUpConfig), under the request's
 * memory cap and allowed its limit; one after another, as the adapter runs
 * every process, none started once the request's deadline has passed. Its
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

    /** What each control found, one after another, until the request's deadline has passed. */
    private function controlled(
        MutationRequest $request,
        Controls $controls,
        OwnConfig $config,
        MemoryScan $scan,
        string $launcher,
    ): ControlRuns {
        file_put_contents($launcher, PeakLauncher::SCRIPT);
        $deadline = $request->deadline();
        $end = $deadline instanceof Seconds ? hrtime(as_number: true) + $deadline->nanoseconds() : PHP_INT_MAX;
        $runs = ControlRuns::none();

        foreach ($controls as $at => $control) {
            if (hrtime(as_number: true) >= $end) {
                break;
            }

            $written = StartUpConfig::holding(
                $this->project,
                $config,
                $control->file(),
                TestFiles::declaring($this->project, $control->tests())->files(),
                sprintf(self::PLACE, Control::DIRECTORY, $at),
            );
            $peak = $written instanceof CannotJudge
                ? $written
                : $this->project->fresh($this->project->own(sprintf(self::PEAK, Control::DIRECTORY, $at)));
            $run = $peak instanceof CannotJudge
                ? ControlRun::unrun($peak->why())
                : $this->ran($written, $peak, $launcher, $control, $request, $config, $scan);
            $runs = $runs->with($control, $run);
        }

        $scan->remove();

        return $runs;
    }

    /** What a control found, run on its config through the launcher, with the peak it wrote. */
    private function ran(
        string $config,
        string $peak,
        string $launcher,
        Control $control,
        MutationRequest $request,
        OwnConfig $own,
        MemoryScan $scan,
    ): ControlRun {
        $ran = $this->shell->run($scan->onto(
            Invocation::controlling($this->project, $own, $config, $control->tests())
                ->withholding($request->withheld())
                ->within($control->limit())
                ->launchedBy($launcher, $peak),
        ));
        $written = is_file($peak) ? (string) file_get_contents($peak) : '';

        return PeakLauncher::measured(ControlRun::ofProcess($ran), $written, PHP_OS_FAMILY);
    }
}
