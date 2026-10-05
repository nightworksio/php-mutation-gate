<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function count;
use function dirname;
use function file_get_contents;
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
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

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

    /** Where a judging run writes its guard, in the directory of the results file. */
    private const string GUARD = '%s/guard.json';

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
        $guard = sprintf(self::GUARD, dirname($results));
        $trial = new Trial(
            $this->project,
            $this->shell,
            $invocation,
            $judgedBy,
            $request->withheld(),
            $guard,
            $scan,
        );
        $originals = new Originals($this->project);
        $mutants = [];

        foreach ($result->mutants() as $mutant) {
            $uncovered = $mutant->status() === MutantStatus::Uncovered;
            $mutants[] = $uncovered ? $this->one($mutant, $selector, $trial, $originals, $results) : $mutant;
        }

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

    private function one(
        Mutant $mutant,
        Selector $selector,
        Trial $trial,
        Originals $originals,
        string $results,
    ): Mutant {
        $file = $mutant->location()->file();
        $copy = Recorder::mutantBeside($results, $mutant->nativeId());
        $choice = $this->choice($selector, $originals->of($file), $copy);

        if ($choice instanceof Outcome) {
            return $this->judged($mutant, $choice);
        }

        if (! $choice instanceof Choice) {
            return $mutant;
        }

        $first = $choice->first($file);
        $outcome = $first instanceof Outcome
            ? $first
            : $trial->of($first, $file, $copy, $selector->limitOf($first, $this->cap));
        $then = $outcome->leftAlive() ? $choice->then($file) : Paths::none();

        $judged = match (true) {
            $then instanceof Outcome => $then,
            count($then) > 0 => $trial->of($then, $file, $copy, $selector->limitOf($then, $this->cap)),
            default => $outcome,
        };

        return $this->judged($mutant, $judged);
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
