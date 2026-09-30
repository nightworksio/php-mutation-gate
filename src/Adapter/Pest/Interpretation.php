<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function implode;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A Pest mutation run read as the gate's records, failing closed: a run that
 * failed, records that do not add up to Pest's own summary, or a filter too
 * long to start a mutant with while `pest:patch` is off is cannot judge. A run
 * stopped at its deadline keeps every result it had, and leaves the rest
 * unjudged. So does a covering test Pest's filter cannot select, because Pest
 * would have called that mutant killed or uncovered without running the test.
 *
 * @phpstan-import-type Planned from Records
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

    public function of(Ran $ran, string $results): MutationResult|CannotJudge
    {
        $records = $this->recordsOf($ran, $results);

        if ($records instanceof CannotJudge) {
            return $records;
        }

        $coverage = CoverageFile::at(Recorder::coverageBeside($results));

        return $coverage instanceof CannotJudge ? $coverage : $this->mutants($records, $coverage);
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

    private function mutants(Records $records, CoverageFile $coverage): MutationResult|CannotJudge
    {
        $mutants = Mutants::none();
        $ids = Identities::of(Root::of($this->project->root()), $records->planned());

        foreach ($records->planned() as $id => $planned) {
            $tests = $coverage->testsCovering($planned['file'], $planned['start'], $planned['end']);
            $selection = Selection::of($tests);

            if (! $selection->fits() && ! $this->patching->isOn()) {
                return CannotJudge::because(sprintf(
                    self::TOO_LONG,
                    $selection->count(),
                    $this->project->relative($planned['file'])->value(),
                    $planned['start'],
                ));
            }

            $mutants = $mutants->with($this->mutant($id, $ids[$id], $planned, $records, $selection));
        }

        return MutationResult::of($mutants, 0);
    }

    /**
     * The one place a Pest mutant becomes the gate's.
     *
     * @param Planned $planned
     */
    private function mutant(string $id, MutantId $gate, array $planned, Records $records, Selection $selection): Mutant
    {
        $path = $this->project->relative($planned['file']);
        $diff = Diff::fromPest($planned['diff']);
        $unselected = $selection->fits() ? $selection->unselected() : [];
        $mutant = Mutant::of(
            $gate,
            $id,
            Location::of($path, Line::of($planned['start']), Line::of($planned['end'])),
            Mutation::of($planned['mutator'], Families::of($planned['mutator']), $diff),
            $unselected === [] ? $this->statusOf($records->statusOf($id)) : MutantStatus::Unjudged,
            $records->durationOf($id),
        );

        $limit = $records->limit();
        $status = $mutant->status();
        $limited = match (true) {
            $status === MutantStatus::TimedOut && $limit instanceof Seconds => $mutant->withLimit($limit),
            $status === MutantStatus::Killed => $mutant->killedBy($records->killersOf($id)),
            default => $mutant,
        };
        $reason = Reason::that(sprintf(self::UNSELECTED, implode(', ', $unselected)));

        return $unselected === [] ? $limited : $limited->because($reason);
    }

    private function statusOf(string $pest): MutantStatus
    {
        return match ($pest) {
            'tested' => MutantStatus::Killed,
            'untested' => MutantStatus::Survived,
            'uncovered' => MutantStatus::Uncovered,
            'timeout' => MutantStatus::TimedOut,
            default => MutantStatus::Unjudged,
        };
    }
}
