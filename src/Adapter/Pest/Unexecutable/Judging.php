<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_keys;
use function array_values;
use function count;
use function dirname;
use function file_get_contents;
use function getmypid;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Covering;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Shell;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function strval;

/**
 * Judges each mutant Pest left uncovered on a line that is not executable,
 * which php-code-coverage leaves out of its map, by the tests that read the
 * value it changes; and on the first line of a statement that spans more,
 * which coverage may not mark run, by the tests that run its other lines
 * (ADR-0004, decision 8). A mutant on any other executable line stays
 * uncovered.
 */
final readonly class Judging
{
    private const string MISSING = 'mutated file missing';

    /** Where the judging runs write their guards and logs, in the directory of the results file. */
    private const string TRIALS = '%s/trials';

    /** @param Seconds $cap `timeouts.seconds`, the most a trial run is allowed */
    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private Seconds $cap,
    ) {
    }

    /**
     * A run's result, with each uncovered mutant on a line that is not
     * executable judged.
     *
     * @param Covering $coverage the map of every file a line that reads a mutant's value may be in
     */
    public function of(
        MutationResult $result,
        MutationRequest $request,
        string $results,
        Covering $coverage,
    ): MutationResult|CannotJudge {
        $judgedBy = $request->judgedBy();

        if ($judgedBy instanceof Filter || ! $this->leftUncovered($result->mutants())) {
            return $result;
        }

        $scan = MemoryScan::beside($this->project, $results, $request->memory(), $this->files);

        if ($scan instanceof CannotJudge) {
            return $scan;
        }

        $selector = Selector::over($this->project, $coverage, $request->files());
        $invocation = Invocation::installedIn($this->project->vendor());
        $trial = new Trial(
            $this->project,
            $this->shell,
            $invocation,
            $judgedBy,
            $request->withheld(),
            sprintf(self::TRIALS, dirname($results)),
            $scan,
            WorkerSlots::of($request->pool()->processes(), strval(getmypid())),
        );
        $mutants = $this->judgedEach([...$result->mutants()], $selector, $trial, $results);
        $scan->remove();

        return MutationResult::of(Mutants::of(...$mutants), $result->skipped());
    }

    private function leftUncovered(Mutants $mutants): bool
    {
        foreach ($mutants as $mutant) {
            if ($mutant->status() === MutantStatus::Uncovered) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each mutant, each uncovered one judged where its line is not
     * executable: first by the tests that read what it changes, their trials
     * side by side, then, for each those leave alive, by the tests that cover
     * the line, theirs side by side too.
     *
     * @param  list<Mutant> $mutants
     * @return list<Mutant>
     */
    private function judgedEach(array $mutants, Selector $selector, Trial $trial, string $results): array
    {
        $choices = $this->choicesOf($mutants, $selector, $results);
        $firsts = [];

        foreach ($choices as $at => $choice) {
            $firsts[$at] = $choice instanceof Choice ? $choice->first($mutants[$at]->location()->file()) : $choice;
        }

        $thens = [];

        foreach ($this->tried($firsts, $mutants, $selector, $trial, $results) as $at => $outcome) {
            $thens[$at] = $this->then($choices[$at], $outcome, $mutants[$at]);
        }

        foreach ($this->tried($thens, $mutants, $selector, $trial, $results) as $at => $outcome) {
            $mutants[$at] = $this->judged($mutants[$at], $outcome);
        }

        return array_values($mutants);
    }

    /**
     * The test files to run where the first leave a mutant alive; what the
     * first found, where none are to run.
     */
    private function then(Choice|Outcome $choice, Outcome $first, Mutant $mutant): Paths|Outcome
    {
        $then = $first->leftAlive() && $choice instanceof Choice
            ? $choice->then($mutant->location()->file())
            : Paths::none();

        return $then instanceof Paths && count($then) === 0 ? $first : $then;
    }

    /**
     * Which test files judge each uncovered mutant, or why it stays
     * unjudged, by its position; none for a mutant coverage speaks for.
     *
     * @param  list<Mutant>                $mutants
     * @return array<int, Choice|Outcome>
     */
    private function choicesOf(array $mutants, Selector $selector, string $results): array
    {
        $choices = [];

        foreach ($mutants as $at => $mutant) {
            $chosen = $mutant->status() === MutantStatus::Uncovered
                ? $this->chosen($mutant, $selector, $results)
                : NotGiven::value();
            $choices += $chosen instanceof NotGiven ? [] : [$at => $chosen];
        }

        return $choices;
    }

    /**
     * What each mutant's tests find, by position: an outcome as it is, and
     * each set of test files tried, the trials side by side.
     *
     * @param  array<int, Paths|Outcome> $tests    each mutant's tests, or what was found without a run, by
     *                                             position
     * @param  list<Mutant>              $mutants
     * @return array<int, Outcome>
     */
    private function tried(array $tests, array $mutants, Selector $selector, Trial $trial, string $results): array
    {
        $found = [];
        $trials = [];

        foreach ($tests as $at => $those) {
            $found += $those instanceof Outcome ? [$at => $those] : [];
            $file = $mutants[$at]->location()->file();
            $copy = Recorder::mutantBeside($results, $mutants[$at]->nativeId());
            $trials += $those instanceof Paths
                ? [$at => TrialRun::of($those, $file, $copy, $selector->limitOf($those, $this->cap))]
                : [];
        }

        foreach ($trial->ofEach(...array_values($trials)) as $position => $outcome) {
            $found[array_keys($trials)[$position]] = $outcome;
        }

        return $found;
    }

    /**
     * Which test files judge an uncovered mutant: none where coverage speaks
     * for its line; or why it stays unjudged.
     */
    private function chosen(Mutant $mutant, Selector $selector, string $results): Choice|NotGiven|Outcome
    {
        $copy = Recorder::mutantBeside($results, $mutant->nativeId());

        return $this->choice($selector, $selector->original($mutant->location()->file()), $copy);
    }

    /**
     * Which test files judge the mutant of a file whose mutated copy, as Pest
     * prints it, is kept at a path; none where coverage speaks for its line;
     * or why it stays unjudged.
     */
    private function choice(
        Selector $selector,
        Original|CannotJudge|NotGiven $original,
        string $copy,
    ): Choice|NotGiven|Outcome {
        $text = is_file($copy) ? file_get_contents($copy) : false;

        if ($original instanceof NotGiven || ! is_string($text)) {
            return Outcome::unjudged(self::MISSING);
        }

        if ($original instanceof CannotJudge) {
            return Outcome::unjudged($original->why());
        }

        $source = $original->source();

        return $selector->judging($source, $source->changedAt($original->printed(), Contents::of($text)));
    }

    /** The mutant as an outcome judges it, with the limit its run was allowed where it timed out. */
    private function judged(Mutant $mutant, Outcome $outcome): Mutant
    {
        $limit = $outcome->limit();
        $judged = Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            $mutant->location(),
            $mutant->mutation(),
            $outcome->status(),
            $outcome->duration(),
        );
        $reason = $outcome->reason();

        return match (true) {
            $reason instanceof Reason => $judged->because($reason),
            $outcome->status() === MutantStatus::TimedOut && $limit instanceof Seconds => $judged->withLimit($limit),
            default => $judged,
        };
    }
}
