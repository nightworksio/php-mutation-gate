<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;

use function sprintf;
use function strval;
use function unlink;

/**
 * Unmutated controls as the PHPUnit runner runs them (ADR-0008, decision 2):
 * each a mutant that changes nothing, its file as the project holds it,
 * served through the override as a mutant's is (see ControlMutant), its tests
 * run as a mutant's are, under the request's memory cap and stopped at its
 * limit, started through the launcher that measures its peak (see
 * PeakLauncher); side by side in the request's pool, none started once the
 * request's deadline has passed. A control whose file cannot be read, or
 * whose run is not started, never runs; one stopped at its limit ran out,
 * whether or not it had served the file yet.
 */
final readonly class UnmutatedRuns
{
    /** Where the launcher of a control at a position writes its peak, in the controls' directory. */
    private const string PEAK = '%s/peak-%d';

    public function __construct(
        private Project $project,
        private Shell $shell,
        private TestFiles $tests,
        private CapFiles $files,
    ) {
    }

    public function of(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        $override = Override::writtenFor($this->project);
        $scan = MemoryScan::in($this->project, $request->memory(), $this->files);

        if (! is_string($override)) {
            return $override;
        }

        if ($scan instanceof CannotJudge) {
            return $scan;
        }

        $judging = new MutantRun(
            $this->project,
            $this->shell,
            new Invocation($this->project, $override),
            $this->tests,
            $scan,
            $this->project->errorDisplay(),
        );
        $runs = $this->controlled($judging, $request, $controls);
        $scan->remove();

        return $runs;
    }

    /** What each control's run found, run as a mutant that changes nothing. */
    private function controlled(MutantRun $judging, MutationRequest $request, Controls $controls): ControlRuns
    {
        $runs = ControlRuns::none();
        $prepared = [];
        $asked = [];

        foreach ($controls as $control) {
            $made = ControlMutant::of($this->project, $control);
            $run = $made instanceof CannotJudge
                ? $made
                : $judging->prepared($made, $control->tests(), $request, $control->limit());

            if ($run instanceof PreparedRun) {
                $prepared[] = $run;
                $asked[] = $control;

                continue;
            }

            $runs = $runs->with(
                $control,
                $run instanceof Mutant ? ControlRun::asMutant($run) : ControlRun::unrun($run->why()),
            );
        }

        return $prepared === [] ? $runs : $this->ended($judging, $request, $runs, $prepared, $asked);
    }

    /**
     * What each prepared control's run found, run side by side through the
     * launcher, with the peak it wrote.
     *
     * @param non-empty-list<PreparedRun> $prepared
     * @param list<Control>               $asked    each prepared run's control, in their order
     */
    private function ended(
        MutantRun $judging,
        MutationRequest $request,
        ControlRuns $runs,
        array $prepared,
        array $asked,
    ): ControlRuns {
        $directory = $this->project->own(Control::DIRECTORY);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $launcher = PeakLauncher::in($directory);
        file_put_contents($launcher, PeakLauncher::SCRIPT);
        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));
        $commands = [];

        foreach ($prepared as $at => $run) {
            $peak = sprintf(self::PEAK, $directory, $at);
            $commands[] = $run->command()->launchedBy($launcher, $this->cleared($peak));
        }

        foreach ($this->shell->sideBySide($slots, $request->deadline(), ...$commands) as $at => $ran) {
            $peak = sprintf(self::PEAK, $directory, $at);
            $written = is_file($peak) ? (string) file_get_contents($peak) : '';
            $runs = $runs->with(
                $asked[$at],
                PeakLauncher::measured($this->found($judging, $prepared[$at], $ran), $written, PHP_OS_FAMILY),
            );
        }

        return $runs;
    }

    /** What a control's run that ended so found: ran out where it was stopped at its limit, served or not. */
    private function found(MutantRun $judging, PreparedRun $prepared, Ran $ran): ControlRun
    {
        return $ran->wasStopped() ? ControlRun::ranOut() : ControlRun::asMutant($judging->finished($prepared, $ran));
    }

    /** A file, with no earlier run's copy of it left. */
    private function cleared(string $file): string
    {
        if (is_file($file)) {
            unlink($file);
        }

        return $file;
    }
}
