<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function dirname;
use function getmypid;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;

use function strval;

/**
 * Unmutated controls as Pest runs them (ADR-0008, decision 2): each
 * control's tests, selected by Pest's filter of their ids where it fits and
 * by the tests that judge the request otherwise, as a mutant's own run
 * selects them, with its file served unmutated through Pest's override (see
 * ServedOriginal), under the request's memory cap and allowed its limit;
 * side by side in the places of the request's pool, none started once the
 * request's deadline has passed. Its tests' time is the time its process
 * took.
 */
final readonly class UnmutatedRuns
{
    /** The file the runs are written beside: the unmutated copies and the memory cap. */
    private const string BESIDE = 'pest/controls/runs';

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

        $runs = ControlRuns::none();
        $invocation = Invocation::installedIn($this->project->vendor());
        $commands = [];
        $running = [];

        foreach ($controls as $control) {
            $served = ServedOriginal::of($this->project, dirname($beside), $control->file());

            if ($served instanceof CannotJudge) {
                $runs = $runs->with($control, ControlRun::unrun($served->why()));

                continue;
            }

            $selection = Selection::of($control->tests());
            $commands[] = $served->onto($scan->onto($invocation->judging(
                Paths::none(),
                $selection->fits() ? $selection->filter() : $request->judgedBy(),
                $request->withheld(),
            )->within($control->limit())));
            $running[] = $control;
        }

        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));
        $ends = $commands === [] ? [] : [...$this->shell->sideBySide($slots, $request->deadline(), ...$commands)];
        $scan->remove();

        foreach ($ends as $at => $ran) {
            $runs = $runs->with($running[$at], ControlRun::ofProcess($ran));
        }

        return $runs;
    }
}
