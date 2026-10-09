<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_map;
use function count;
use function file_put_contents;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\PrunedList;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\StartUpVariable;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * One run of Infection over a coverage directory: the config the gate writes
 * for it, the command, and the results its logs hold. The logs of an earlier
 * run are removed first, so a run that writes none is never read from them.
 */
final readonly class MutationRun
{
    private const string STOPPED
        = 'Infection was stopped at its deadline, before it wrote its log, so no mutant of this run has a result.';

    /** What a run of an Infection that does not carry `infection:patch` warns of. */
    private const string UNPATCHED
        = 'Unpatched, Infection gave each mutant its own limit, with no floor. Run mutation-gate infection:patch.';

    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private OwnConfig $config,
        private bool $nativeMarkersAllowed,
        private StaticAnalysis $analysis,
        private PatchState $state,
        private Bridges $bridges = new Bridges(),
    ) {
    }

    /**
     * The request's mutants, judged with the coverage in a directory, each
     * mutant's limit within the bounds where Infection is patched, and
     * Infection's own under the most where it is not, which the result
     * warns of.
     */
    public function of(
        MutationRequest $request,
        DiskPath $coverage,
        LimitBounds $bounds,
        Laps $laps,
    ): MutationResult|CannotJudge {
        $from = $laps->now();
        $limits = $this->limits($coverage, $bounds);
        $read = $laps->lap(Step::Coverage, $from);

        if ($limits instanceof CannotJudge) {
            return $limits;
        }

        $from = $laps->now();
        $targets = $this->prepared($request, $bounds->most());
        $prepared = StepTimes::of($read, $laps->lap(Step::Preparing, $from));
        $result = $targets instanceof CannotJudge
            ? $targets
            : $this->ran($request, $coverage, $targets, $limits, $bounds, $laps);
        $result = $result instanceof CannotJudge ? $result : $result->withStepsBefore($prepared);

        return $result instanceof CannotJudge || $this->state === PatchState::Applied
            ? $result
            : $result->withWarnings(Warnings::of(Warning::that(self::UNPATCHED)));
    }

    /**
     * The list of the mutators the run leaves out of its unchanged files,
     * for the patched Infection; none where it leaves none out.
     *
     * @return array<string, string>
     */
    private function pruning(Pruned $pruned): array
    {
        return $pruned->isNone() ? [] : [ChildVariable::Pruned->value => PrunedFile::write(
            PrunedList::beside($this->project->own(Invocation::SILENCED)),
            $pruned,
            $this->project->root(),
        )];
    }

    private function ran(
        MutationRequest $request,
        DiskPath $coverage,
        Targets $targets,
        Limits $limits,
        LimitBounds $bounds,
        Laps $laps,
    ): MutationResult|CannotJudge {
        $invoked = Invocation::mutation(
            $this->project,
            $this->config,
            $request->judgedBy(),
            $coverage,
            $request->pool()->processes(),
            $targets->paths(),
            $request->narrowing()->suite(),
        )->withholding($request->withheld())->within($request->deadline())->with([
            ChildVariable::MutantFloor->value => sprintf('%F', $bounds->floor()->seconds()),
            ChildVariable::Results->value => $this->project->own(Invocation::SILENCED),
            ...TighterVariables::of($bounds->tighter()),
            ...StartUpVariable::of($bounds),
            ...$this->pruning($request->narrowing()->pruned()),
        ]);
        $scan = MemoryScan::in($this->project, $request->memory(), $this->files);

        if ($scan instanceof CannotJudge) {
            return $scan;
        }

        $from = $laps->now();
        $ran = $this->shell->run($scan->onto($invoked));
        $mutation = $laps->lap(Step::Mutation, $from);
        $scan->remove();
        $from = $laps->now();
        $result = $ran->wasStopped()
            ? CannotJudge::because(self::STOPPED)
            : Results::read(
                $this->project,
                $ran,
                TextLog::at($this->project->own(Invocation::TEXT)),
                $limits->silencedAt(Silenced::in($this->project->own(Invocation::SILENCED))),
                $request->memory(),
                ProjectPhpUnit::display($this->project, $this->config),
                $this->nativeMarkersAllowed,
                $this->bridges,
            );

        return $result instanceof CannotJudge ? $result : $result->withSteps(StepTimes::of(
            StepTime::counted($mutation, count($result->mutants())),
            $laps->lap(Step::Reading, $from),
        ));
    }

    /** What Infection allows each mutant, from the coverage the run reads. */
    private function limits(DiskPath $coverage, LimitBounds $bounds): Limits|CannotJudge
    {
        $map = CoverageXml::read($this->project, $coverage);
        $junit = JUnit::at($coverage->child(Invocation::JUNIT));

        return match (true) {
            $map instanceof CannotJudge => $map,
            $junit instanceof CannotJudge => $junit,
            default => Limits::of($map, $junit, $bounds, $this->state),
        };
    }

    /**
     * The paths the run mutates, with its config written, the bridges to the
     * registered mutators where there are any, and no earlier run's logs
     * left, or why not.
     */
    private function prepared(MutationRequest $request, Seconds $cap): Targets|CannotJudge
    {
        $targets = Targets::of($this->project, $request->files(), $request->leftOut());
        $bridged = ! $this->bridges->isEmpty();
        $files = [
            $this->project->own(Invocation::JSON),
            $this->project->own(Invocation::TEXT),
            $this->project->own(Invocation::SILENCED),
            $this->project->own(Invocation::CONFIG),
            ...$bridged ? [$this->project->bridges()] : [],
        ];

        foreach ([$this->bridges->refusal(), ...array_map($this->project->fresh(...), $files)] as $prepared) {
            if ($prepared instanceof CannotJudge) {
                return $prepared;
            }
        }

        if ($bridged) {
            $bootstrap = $this->config->bootstrap($this->project);
            file_put_contents($this->project->bridges(), $this->bridges->written($bootstrap));
        }

        file_put_contents(
            $this->project->own(Invocation::CONFIG),
            $this->config->generated(
                $this->project,
                $targets->directories(),
                $cap,
                $request->narrowing()->mutators(),
                $this->analysis,
                $this->bridges,
            ),
        );

        return $targets;
    }
}
