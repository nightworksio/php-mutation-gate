<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A Pest mutation run read as the gate's records, failing closed: a run that
 * failed, records that do not add up to Pest's own summary, or a filter too
 * long to start a mutant with while `pest:patch` is off is cannot judge, and
 * one whose own process ran out of the memory cap says to raise it. A run
 * stopped at its deadline keeps every result it had, and leaves the rest
 * unjudged. So does a covering test Pest's filter cannot select, because Pest
 * would have called that mutant killed or uncovered without running the test,
 * and a mutant whose own process had loaded its file before the mutant was in
 * place, because its tests ran the original code.
 */
final readonly class Interpretation
{
    private const string FAILED = "Pest's mutation run failed. Pest said:\n%s";

    private const string UNCOUNTED = "Pest's records of its mutants do not add up to its summary. Pest said:\n%s";

    private const string STOPPED_EARLY = 'Pest was stopped at its deadline before it had made its mutants.';

    private const string TOO_LONG = 'Pest cannot pass the filter of the %d tests covering %s:%d. Turn on pest.patch.';

    private const string UNSELECTED = "Pest's --filter cannot select %s, so Pest cannot run it against this mutant.";

    private const string PRELOADED = '%s was loaded before the mutant was in place, so its tests ran the original code';

    private const string OUT_OF_MEMORY
        = "Pest ran out of the %s memory cap in its own process, so the run did not finish. %s Pest said:\n%s";

    public function __construct(
        private Project $project,
        private Patching $patching,
        private MemoryCap $cap,
        private Bridges $bridges = new Bridges(),
    ) {
    }

    /** A run's records, read with the covering tests of the run's opening map. */
    public function of(Ran $ran, string $results, Covering|CannotJudge $coverage): MutationResult|CannotJudge
    {
        $records = $this->recordsOf($ran, $results);

        return match (true) {
            $records instanceof CannotJudge => $records,
            $coverage instanceof CannotJudge => $coverage,
            default => $this->mutants($records, $coverage),
        };
    }

    /** A mutant the gate made before, now unjudged, saying why. */
    public static function unjudged(Mutant $mutant, Reason $reason): Mutant
    {
        return Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            $mutant->location(),
            $mutant->mutation(),
            MutantStatus::Unjudged,
            $mutant->duration(),
        )->because($reason);
    }

    /**
     * The records of a run that reached its end, whatever its exit code, since
     * a project's own minimum score fails a run that finished; of a run
     * stopped at its deadline once every mutant was written; and no others.
     */
    private function recordsOf(Ran $ran, string $results): Records|CannotJudge
    {
        $records = Records::in($results);

        return match (true) {
            $records instanceof CannotJudge => $ran->succeeded() || $ran->wasStopped() ? $records : $this->failed($ran),
            $ran->wasStopped() => $records->allMade() ? $records : CannotJudge::because(self::STOPPED_EARLY),
            $ran->succeeded() || $records->ended() => $this->counted($records, $ran),
            default => $this->failed($ran),
        };
    }

    /** Why a run that failed cannot be judged: Pest's own process out of the memory cap, or what Pest said. */
    private function failed(Ran $ran): CannotJudge
    {
        $output = $ran->output();

        return Exhaustion::isOf(Exhaustion::in($output), $this->cap)
            ? CannotJudge::because(sprintf(self::OUT_OF_MEMORY, $this->cap->written(), Exhaustion::ADVICE, $output))
            : CannotJudge::because(sprintf(self::FAILED, $output));
    }

    private function counted(Records $records, Ran $ran): Records|CannotJudge
    {
        $summary = Summary::in($ran->output());

        if ($summary instanceof CannotJudge) {
            return $summary;
        }

        return $records->addUpTo($summary) ? $records : CannotJudge::because(sprintf(self::UNCOUNTED, $ran->output()));
    }

    private function mutants(Records $records, Covering $coverage): MutationResult|CannotJudge
    {
        $mutants = [];
        $planned = $records->planned();
        $ids = Identities::of(Root::of($this->project->root()), $planned);

        foreach ($planned as $at => $mutant) {
            $selection = Selection::of($coverage->testsCovering($mutant->file(), $mutant->start(), $mutant->end()));

            if (! $selection->fits() && ! $this->patching->isOn()) {
                return CannotJudge::because(sprintf(
                    self::TOO_LONG,
                    $selection->count(),
                    $this->project->relative($mutant->file()->value())->value(),
                    $mutant->start()->number(),
                ));
            }

            $mutants[] = $this->mutant($ids[$at], $mutant, $records, $selection);
        }

        return MutationResult::of(Mutants::of(...$mutants), 0);
    }

    /**
     * A mutant's status as Pest ended it; a kill whose process ran out of
     * exactly the gate's memory cap is out of memory (ADR-0004, decision 9).
     */
    private function statusOf(PlannedMutant $planned, Records $records): MutantStatus
    {
        $status = $records->statusOf($planned)->status();

        return $status === MutantStatus::Killed && Exhaustion::isOf($records->runOf($planned)->exhaustion(), $this->cap)
            ? MutantStatus::OutOfMemory
            : $status;
    }

    /** The one place a Pest mutant becomes the gate's. */
    private function mutant(MutantId $gate, PlannedMutant $planned, Records $records, Selection $selection): Mutant
    {
        $unselected = $selection->fits() ? $selection->unselected() : TestIds::none();
        $file = $this->project->relative($planned->file()->value());
        $ranTheOriginal = $records->runOf($planned)->ranTheOriginal();
        $judged = count($unselected) === 0 && ! $ranTheOriginal;
        $mutant = Mutant::of(
            $gate,
            $planned->id(),
            Location::of($file, $planned->start(), $planned->end()),
            Mutation::of(
                $planned->mutator(),
                $this->bridges->familyOf($planned->mutator()),
                Diff::fromPest($planned->diff()),
            ),
            $judged ? $this->statusOf($planned, $records) : MutantStatus::Unjudged,
            $records->durationOf($planned),
        );

        $limit = $records->limit();
        $status = $mutant->status();
        $limited = match (true) {
            $status === MutantStatus::OutOfMemory => $mutant->withLimit($this->cap),
            $status === MutantStatus::TimedOut && $limit instanceof Seconds => $mutant->withLimit($limit),
            $status === MutantStatus::Killed => $mutant->killedBy($records->runOf($planned)->killers()),
            default => $mutant,
        };
        $names = array_map(static fn(TestId $test): string => $test->value(), [...$unselected]);

        return match (true) {
            $ranTheOriginal => $limited->because(Reason::that(sprintf(self::PRELOADED, $file->value()))),
            $judged => $limited,
            default => $limited->because(Reason::that(sprintf(self::UNSELECTED, implode(', ', $names)))),
        };
    }
}
