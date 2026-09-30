<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_count_values;
use function array_key_exists;
use function implode;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
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

    private function recordsOf(Ran $ran, string $results): Records|CannotJudge
    {
        if (! $ran->succeeded() && ! $ran->wasStopped()) {
            return CannotJudge::because(sprintf(self::FAILED, $ran->output()));
        }

        $records = Records::in($results);

        if ($records instanceof CannotJudge) {
            return $records;
        }

        return $ran->wasStopped() ? $this->stopped($records) : $this->counted($records, $ran);
    }

    private function stopped(Records $records): Records|CannotJudge
    {
        return $records->planned() === [] ? CannotJudge::because(self::STOPPED_EARLY) : $records;
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
        $seen = [];

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

            $path = $this->project->relative($planned['file']);
            $key = sprintf("%s\n%s\n%s", $path->value(), $planned['mutator'], Diff::fromPest($planned['diff']));
            $occurrence = $this->occurrences($seen, $key);
            $mutants = $mutants->with($this->mutant($id, $planned, $records, $selection, $occurrence));
            $seen[] = $key;
        }

        return MutationResult::of($mutants, 0);
    }

    /**
     * The one place a Pest mutant becomes the gate's.
     *
     * @param Planned $planned
     * @param int     $occurrence how many mutants before this one share its file, mutator and change
     */
    private function mutant(string $id, array $planned, Records $records, Selection $selection, int $occurrence): Mutant
    {
        $path = $this->project->relative($planned['file']);
        $diff = Diff::fromPest($planned['diff']);
        $unselected = $selection->fits() ? $selection->unselected() : [];
        $mutant = Mutant::of(
            MutantId::hash($path, $planned['mutator'], $diff, $occurrence),
            $id,
            Location::of($path, Line::of($planned['start']), Line::of($planned['end'])),
            Mutation::of($planned['mutator'], Families::of($planned['mutator']), $diff),
            $unselected === [] ? $this->statusOf($records->statusOf($id)) : MutantStatus::Unjudged,
            $records->durationOf($id),
        );

        $limit = $records->limit();
        $limited = $mutant->status() === MutantStatus::TimedOut && $limit instanceof Seconds
            ? $mutant->withLimit($limit)
            : $mutant;
        $reason = Reason::that(sprintf(self::UNSELECTED, implode(', ', $unselected)));

        return $unselected === [] ? $limited : $limited->because($reason);
    }

    /** @param list<string> $seen the key of every mutant before this one */
    private function occurrences(array $seen, string $key): int
    {
        $counts = array_count_values($seen);

        return array_key_exists($key, $counts) ? $counts[$key] : 0;
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
