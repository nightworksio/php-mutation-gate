<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_put_contents;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * One run of Infection over a coverage directory: the config the gate writes
 * for it, the command, and the results its logs hold. The logs of an earlier
 * run are removed first, so a run that writes none is never read from them.
 */
final readonly class MutationRun
{
    private const string STOPPED
        = 'Infection was stopped at its deadline, before it wrote its log, so no mutant of this run has a result.';

    public function __construct(
        private Project $project,
        private Shell $shell,
        private OwnConfig $config,
        private bool $nativeMarkersAllowed,
    ) {
    }

    /** The request's mutants, judged with the coverage in a directory, each allowed at most the cap. */
    public function of(MutationRequest $request, DiskPath $coverage, Seconds $cap): MutationResult|CannotJudge
    {
        $limits = $this->limits($coverage, $cap);

        if ($limits instanceof CannotJudge) {
            return $limits;
        }

        $targets = $this->prepared($request, $cap);

        return $targets instanceof CannotJudge ? $targets : $this->ran($request, $coverage, $targets, $limits);
    }

    private function ran(
        MutationRequest $request,
        DiskPath $coverage,
        Targets $targets,
        Limits $limits,
    ): MutationResult|CannotJudge {
        $ran = $this->shell->run(Invocation::mutation(
            $this->project,
            $this->config,
            $request->judgedBy(),
            $coverage,
            $request->processes(),
            $targets->paths(),
        )->withholding($request->withheld())->within($request->deadline()));

        return $ran->wasStopped()
            ? CannotJudge::because(self::STOPPED)
            : Results::read(
                $this->project,
                $ran,
                TextLog::at($this->project->own(Invocation::TEXT)),
                $limits,
                $this->nativeMarkersAllowed,
            );
    }

    /** What Infection allows each mutant, from the coverage the run reads. */
    private function limits(DiskPath $coverage, Seconds $cap): Limits|CannotJudge
    {
        $map = CoverageXml::read($this->project, $coverage);
        $junit = JUnit::at($coverage->child(Invocation::JUNIT));

        return match (true) {
            $map instanceof CannotJudge => $map,
            $junit instanceof CannotJudge => $junit,
            default => Limits::of($map, $junit, $cap),
        };
    }

    /** The paths the run mutates, with its config written and no earlier run's logs left, or why not. */
    private function prepared(MutationRequest $request, Seconds $cap): Targets|CannotJudge
    {
        $targets = Targets::of($this->project, $request->files(), $request->leftOut());

        foreach ([Invocation::JSON, Invocation::TEXT, Invocation::CONFIG] as $name) {
            $fresh = $this->project->fresh($this->project->own($name));

            if ($fresh instanceof CannotJudge) {
                return $fresh;
            }
        }

        file_put_contents(
            $this->project->own(Invocation::CONFIG),
            $this->config->generated($this->project, $targets->directories(), $cap, $request->mutators()),
        );

        return $targets;
    }
}
