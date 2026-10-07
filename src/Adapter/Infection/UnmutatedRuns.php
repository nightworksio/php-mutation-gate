<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function hrtime;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
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
 * tests' time is the time its process took.
 */
final readonly class UnmutatedRuns
{
    /** The place of a control's files among the adapter's own files, by its position. */
    private const string PLACE = 'controls/%d';

    public function __construct(private Project $project, private Shell $shell, private CapFiles $files)
    {
    }

    public function of(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        $config = OwnConfig::in($this->project);

        if ($config instanceof CannotJudge) {
            return $config;
        }

        $scan = MemoryScan::in($this->project, $request->memory(), $this->files);

        if ($scan instanceof CannotJudge) {
            return $scan;
        }

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
                sprintf(self::PLACE, $at),
            );
            $run = $written instanceof CannotJudge
                ? ControlRun::unrun($written->why())
                : ControlRun::ofProcess($this->shell->run($scan->onto(
                    Invocation::controlling($this->project, $config, $written, $control->tests())
                        ->withholding($request->withheld())
                        ->within($control->limit()),
                )));
            $runs = $runs->with($control, $run);
        }

        $scan->remove();

        return $runs;
    }
}
