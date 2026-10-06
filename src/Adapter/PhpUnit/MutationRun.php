<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_filter;
use function count;
use function file_get_contents;
use function getmypid;
use function hrtime;
use function in_array;
use function iterator_to_array;

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Forked;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workforce;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;
use function strval;

/**
 * Every mutant of a request's files the gate's own engine makes with the
 * enabled mutators (ADR-0023 decision 8), each judged by the tests the
 * coverage map says cover its lines, and allowed the standard mutant limit of
 * their time as the map timed it (ADR-0008, decision 2). Their runs go side by
 * side, as many at once as the request's processes, each process told its
 * place as paratest tells its workers: each forked from a warm worker where
 * the request's pool asks for that and a worker can fork, and in a fresh
 * process otherwise (ADR-0023, decisions 12 to 14). A mutant no test covers
 * is uncovered, without a run. A mutant whose run has not started by the
 * request's deadline is skipped with no record. A run again makes only the
 * mutants it names.
 */
final readonly class MutationRun
{
    /**
     * How many runs a batch holds for each place, so that each place has a
     * queue of runs while a batch runs, and few places stand idle as the
     * batch's last runs end: with runs alike, about a thirty-second of a
     * batch's time.
     */
    private const int RUNS_PER_PLACE = 16;

    public function __construct(
        private Project $project,
        private Engine $engine,
        private MutantRun $run,
        private Workforce $workforce,
        private MutantIds|NotGiven $only = new NotGiven(),
    ) {
    }

    /** This run, making only the mutants with these ids, as a run again does. */
    public function makingOnly(MutantIds $ids): self
    {
        return new self($this->project, $this->engine, $this->run, $this->workforce, $ids);
    }

    /** Each mutant, each of its runs stopped at its limit under this cap. */
    public function of(MutationRequest $request, CoverageMap $map, Seconds $cap): MutationResult|CannotJudge
    {
        $made = $this->made($request);
        $judged = $made instanceof CannotJudge ? $made : $this->judgedAll($made, $request, $map, $cap);

        return match (true) {
            $judged instanceof CannotJudge => $judged,
            default => MutationResult::of(Mutants::of(...$judged->mutants()), count($made) - count($judged->mutants()))
                ->withWarnings($judged->warnings()),
        };
    }

    /**
     * Each mutant judged, until the request's deadline: one no test covers
     * without a run, and the rest by their runs, forked from warm workers
     * where the request asks for them and fresh otherwise, in the order they
     * were made; or why one cannot be.
     *
     * @param list<MadeMutant> $made
     */
    private function judgedAll(
        array $made,
        MutationRequest $request,
        CoverageMap $map,
        Seconds $cap,
    ): Judged|CannotJudge {
        $end = $this->endOf($request->deadline());
        $queue = [];

        foreach ($made as $mutant) {
            if (hrtime(as_number: true) >= $end) {
                break;
            }

            $prepared = $this->prepared($mutant, $map, $request, $cap);

            if ($prepared instanceof CannotJudge) {
                return $prepared;
            }

            $queue[] = $prepared;
        }

        $runs = array_filter($queue, static fn(PreparedRun|Mutant $prepared): bool => $prepared instanceof PreparedRun);
        $forked = $request->pool()->workers() === Workers::Fork
            ? $this->workforce->judged($request, $end, $runs)
            : Forked::nothing();

        return $this->freshly($queue, $forked, $request, $end);
    }

    /**
     * Each mutant in its order: as a warm worker's child judged it, without a
     * run, or by a fresh run, side by side a batch at a time, while the time
     * to start runs in lasts.
     *
     * @param list<PreparedRun|Mutant> $queue
     */
    private function freshly(array $queue, Forked $forked, MutationRequest $request, int|float $end): Judged
    {
        $processes = $request->pool()->processes();
        $batch = PreparedBatch::of(
            $this->run,
            WorkerSlots::of($processes, strval(getmypid())),
            self::RUNS_PER_PLACE * $processes->count(),
            $end,
        );

        foreach ($queue as $at => $prepared) {
            $judged = $forked->judgedAt($at);
            $next = $judged instanceof Mutant ? $judged : $prepared;

            if ($next instanceof Mutant || ! $batch->hasRunOut()) {
                $batch->add($next);
            }
        }

        return Judged::of($batch->finished(), $forked->warnings());
    }

    /**
     * The mutant's run, ready to start; the mutant without a run, where no
     * test covers it; or why it cannot be written.
     */
    private function prepared(
        MadeMutant $mutant,
        CoverageMap $map,
        MutationRequest $request,
        Seconds $cap,
    ): PreparedRun|Mutant|CannotJudge {
        $covering = $this->covering($map, $mutant);

        return count($covering) === 0
            ? Mutant::of(
                $mutant->id(),
                $mutant->id()->value(),
                $mutant->location(),
                $mutant->mutation(),
                MutantStatus::Uncovered,
                Unmeasured::duration(),
            )
            : $this->run->prepared(
                $mutant,
                $covering,
                $request,
                MutantLimit::standard()->of(OwnTime::of($map, $covering), $cap),
            );
    }

    /**
     * Every mutant the request asks for, file by file in byte order, or why a
     * file cannot be mutated.
     *
     * @return list<MadeMutant>|CannotJudge
     */
    private function made(MutationRequest $request): array|CannotJudge
    {
        $asked = [];

        foreach (PhpFiles::in($this->project, $request->files(), $request->leftOut()) as $file) {
            $code = Contents::of(sprintf('%s', file_get_contents($this->project->absolute($file))));
            $made = $this->engine->mutantsOf($file, $code);

            if ($made instanceof CannotJudge) {
                return $made;
            }

            foreach ($made as $mutant) {
                $asked = $this->isAsked($request, $mutant) ? [...$asked, $mutant] : $asked;
            }
        }

        return $asked;
    }

    /**
     * Whether the run asks for the mutant: the request asks for every mutator
     * or names its own, and the run names the mutant where it names any.
     */
    private function isAsked(MutationRequest $request, MadeMutant $mutant): bool
    {
        $mutators = $request->narrowing()->mutators();
        $named = iterator_to_array($mutators, preserve_keys: false);
        $mutator = $mutators->isAll() || in_array($mutant->mutation()->mutator(), $named, strict: true);

        return $mutator && ($this->only instanceof NotGiven || $this->only->has($mutant->id()));
    }

    /** Every test that covers a line the mutant changes. */
    private function covering(CoverageMap $map, MadeMutant $mutant): TestIds
    {
        $location = $mutant->location();

        return $map->testsCoveringSpan($location->file(), $location->start(), $location->last());
    }

    /** When the run stops making mutants, on `hrtime`'s clock: never, where it has no deadline. */
    private function endOf(Seconds|Unlimited $deadline): int|float
    {
        return $deadline instanceof Seconds ? hrtime(as_number: true) + $deadline->nanoseconds() : PHP_INT_MAX;
    }
}
