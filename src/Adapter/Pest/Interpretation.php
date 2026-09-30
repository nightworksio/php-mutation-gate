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
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A Pest mutation run read as the gate's records, failing closed: a run that
 * failed, records that do not add up to Pest's own summary, or a filter too
 * long to start a mutant with while `pest:patch` is off is cannot judge. A run
 * stopped at its deadline keeps every result it had, and leaves the rest
 * unjudged. So does a covering test Pest's filter cannot select, because Pest
 * would have called that mutant killed or uncovered without running the test.
 */
final readonly class Interpretation
{
    private const string FAILED = "Pest's mutation run failed. Pest said:\n%s";

    private const string UNCOUNTED = "Pest's records of its mutants do not add up to its summary. Pest said:\n%s";

    private const string STOPPED_EARLY = 'Pest was stopped at its deadline before it had made its mutants.';

    private const string TOO_LONG = 'Pest cannot pass the filter of the %d tests covering %s:%d. Turn on pest.patch.';

    private const string UNSELECTED = "Pest's --filter cannot select %s, so Pest cannot run it against this mutant.";

    public function __construct(private Project $project, private Patching $patching)
    {
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
            $records instanceof CannotJudge => $ran->succeeded() || $ran->wasStopped()
                ? $records
                : CannotJudge::because(sprintf(self::FAILED, $ran->output())),
            $ran->wasStopped() => $records->allMade() ? $records : CannotJudge::because(self::STOPPED_EARLY),
            $ran->succeeded() || $records->ended() => $this->counted($records, $ran),
            default => CannotJudge::because(sprintf(self::FAILED, $ran->output())),
        };
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

    /** The one place a Pest mutant becomes the gate's. */
    private function mutant(MutantId $gate, PlannedMutant $planned, Records $records, Selection $selection): Mutant
    {
        $unselected = $selection->fits() ? $selection->unselected() : TestIds::none();
        $judged = count($unselected) === 0;
        $mutant = Mutant::of(
            $gate,
            $planned->id(),
            Location::of($this->project->relative($planned->file()->value()), $planned->start(), $planned->end()),
            Mutation::of($planned->mutator(), Families::of($planned->mutator()), Diff::fromPest($planned->diff())),
            $judged ? $records->statusOf($planned)->status() : MutantStatus::Unjudged,
            $records->durationOf($planned),
        );

        $limit = $records->limit();
        $status = $mutant->status();
        $limited = match (true) {
            $status === MutantStatus::TimedOut && $limit instanceof Seconds => $mutant->withLimit($limit),
            $status === MutantStatus::Killed => $mutant->killedBy($records->killersOf($planned)),
            default => $mutant,
        };
        $names = array_map(static fn(TestId $test): string => $test->value(), [...$unselected]);

        return $judged ? $limited : $limited->because(Reason::that(sprintf(self::UNSELECTED, implode(', ', $names))));
    }
}
