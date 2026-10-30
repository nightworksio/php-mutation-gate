<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_values;

use Closure;

use function count;
use function dirname;
use function file_get_contents;
use function getmypid;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Shell;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\Filter;

use function sprintf;
use function strval;

/**
 * Judges each mutant Pest left uncovered on a line that is not executable,
 * which php-code-coverage leaves out of its map, by the tests that read the
 * value it changes; and on the first line of a statement that spans more,
 * which coverage may not mark run, by the tests that run its other lines
 * (ADR-0004). A mutant on any other executable line stays
 * uncovered.
 */
final readonly class Judging
{
    private const string MISSING = 'mutated file missing';

    /** Where the judging runs write their guards and logs, in the directory of the results file. */
    private const string TRIALS = '%s/trials';

    /** @param LimitBounds $bounds what a trial run's limit is kept between */
    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private LimitBounds $bounds,
    ) {
    }

    /**
     * A run's result, with each uncovered mutant on a line that is not
     * executable judged.
     *
     * @param Closure(): Selector $scanning the scan of the codebase that reads the map of every file a line that reads
     *                                     a mutant's value may be in, made only where a mutant needs it
     */
    public function of(
        MutationResult $result,
        MutationRequest $request,
        string $results,
        Closure $scanning,
    ): MutationResult|CannotJudge {
        $judgedBy = $request->judgedBy();

        if ($judgedBy instanceof Filter || ! $this->leftUncovered($result->mutants())) {
            return $result;
        }

        $scan = MemoryScan::beside($this->project, $results, $request->memory(), $this->files);

        if ($scan instanceof CannotJudge) {
            return $scan;
        }

        $selector = $scanning();
        $invocation = Invocation::of($this->project);
        $trial = new Trial(
            $this->project,
            $this->shell,
            $invocation,
            $judgedBy,
            $request->withheld(),
            sprintf(self::TRIALS, dirname($results)),
            $scan,
            WorkerSlots::of($request->pool()->processes(), strval(getmypid())),
            $request->search()->matrix(),
        );
        [$mutants, $evidence] = $this->judgedEach([...$result->mutants()], $selector, $trial, $results);
        $scan->remove();

        return $result->withMutants(Mutants::of(...$mutants))->withEvidence($evidence);
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
     * the line, theirs side by side too; and the evidence of each kill a
     * trial made.
     *
     * @param  list<Mutant>                     $mutants
     * @return array{list<Mutant>, Evidences}
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

        $evidence = Evidences::none();

        foreach ($this->tried($thens, $mutants, $selector, $trial, $results) as $at => $outcome) {
            $mutants[$at] = $outcome->judging($mutants[$at]);
            $evidence = $evidence->with($mutants[$at]->id(), $outcome->evidence());
        }

        return [array_values($mutants), $evidence];
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
     * each set of test files tried, in one trial or in parts that each fit
     * under the most (see TrialParts), the trials side by side.
     *
     * @param  array<int, Paths|Outcome> $tests    each mutant's tests, or what was found without a run, by
     *                                             position
     * @param  list<Mutant>              $mutants
     * @return array<int, Outcome>
     */
    private function tried(array $tests, array $mutants, Selector $selector, Trial $trial, string $results): array
    {
        $found = [];
        $parted = [];

        foreach ($tests as $at => $those) {
            $found += $those instanceof Outcome ? [$at => $those] : [];
            $file = $mutants[$at]->location()->file();
            $copy = Recorder::mutantBeside($results, $mutants[$at]->nativeId());
            $parted += $those instanceof Paths
                ? [$at => PartedTrial::of($selector->trialsOf($those, $file, $copy, $this->bounds))]
                : [];
        }

        return $found + PartedTrials::of($parted)->triedBy($trial);
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
        $text = is_file($copy) ? file_get_contents($copy) : null;

        if ($original instanceof NotGiven || ! is_string($text)) {
            return Outcome::unjudged(self::MISSING);
        }

        if ($original instanceof CannotJudge) {
            return Outcome::unjudged($original->why());
        }

        $source = $original->source();

        return $selector->judging($source, $source->changedAt($original->printed(), Contents::of($text)));
    }
}
