<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;
use function file_get_contents;
use function hrtime;
use function in_array;
use function iterator_to_array;

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
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;

/**
 * Every mutant of a request's files the gate's own engine makes with the
 * enabled mutators (ADR-0023 decision 8), one after another, each judged by
 * the tests the coverage map says cover its lines, and allowed the standard
 * mutant limit of their time as the map timed it (ADR-0008, decision 2). A
 * mutant no test covers is uncovered, without a run. A mutant not reached by the request's deadline is
 * skipped with no record. A run again makes only the mutants it names.
 */
final readonly class MutationRun
{
    public function __construct(
        private Project $project,
        private Engine $engine,
        private MutantRun $run,
        private MutantIds|NotGiven $only = new NotGiven(),
    ) {
    }

    /** This run, making only the mutants with these ids, as a run again does. */
    public function makingOnly(MutantIds $ids): self
    {
        return new self($this->project, $this->engine, $this->run, $ids);
    }

    /** Each mutant, each of its runs stopped at its limit under this cap. */
    public function of(MutationRequest $request, CoverageMap $map, Seconds $cap): MutationResult|CannotJudge
    {
        $made = $this->made($request);

        if ($made instanceof CannotJudge) {
            return $made;
        }

        $mutants = [];
        $end = $this->endOf($request->deadline());

        foreach ($made as $mutant) {
            if (hrtime(as_number: true) >= $end) {
                break;
            }

            $covering = $this->covering($map, $mutant);
            $limit = MutantLimit::standard()->of(OwnTime::of($map, $covering), $cap);
            $judged = $this->judged($mutant, $covering, $request, $limit);

            if ($judged instanceof CannotJudge) {
                return $judged;
            }

            $mutants[] = $judged;
        }

        return MutationResult::of(Mutants::of(...$mutants), count($made) - count($mutants));
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

    private function judged(
        MadeMutant $mutant,
        TestIds $covering,
        MutationRequest $request,
        Seconds $limit,
    ): Mutant|CannotJudge {
        return count($covering) === 0
            ? Mutant::of(
                $mutant->id(),
                $mutant->id()->value(),
                $mutant->location(),
                $mutant->mutation(),
                MutantStatus::Uncovered,
                Unmeasured::duration(),
            )
            : $this->run->judged($mutant, $covering, $request, $limit);
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
