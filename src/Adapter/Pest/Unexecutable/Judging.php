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
use NightWorksIO\MutationGate\Adapter\Pest\Records;
use NightWorksIO\MutationGate\Adapter\Pest\Shell;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Php\Executable;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * Judges each mutant Pest left uncovered on a line that is not executable,
 * which php-code-coverage leaves out of its map, by the tests that read the
 * value it changes (ADR-0004, decision 8). A mutant on an executable line
 * stays uncovered.
 */
final readonly class Judging
{
    private const string MISSING = 'mutated file missing';

    /** Where a judging run writes its guard, in the directory of the results file. */
    private const string GUARD = '%s/guard.json';

    public function __construct(private Project $project, private Shell $shell)
    {
    }

    /** A run's result, with each uncovered mutant on a line that is not executable judged. */
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

        $records = Records::in($results);
        $scan = MemoryScan::beside($this->project, $results, $request->memory());

        if ($records instanceof CannotJudge || $scan instanceof CannotJudge) {
            return $records instanceof CannotJudge ? $records : $scan;
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
            $records->limit(),
            $guard,
            $scan,
        );
        $mutants = Mutants::none();

        foreach ($result->mutants() as $mutant) {
            $uncovered = $mutant->status() === MutantStatus::Uncovered;
            $mutants = $mutants->with($uncovered ? $this->one($mutant, $selector, $trial, $results) : $mutant);
        }

        return MutationResult::of($mutants, $result->skipped());
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

    private function one(Mutant $mutant, Selector $selector, Trial $trial, string $results): Mutant
    {
        $file = $mutant->location()->file();
        $copy = Recorder::mutantBeside($results, $mutant->nativeId());
        $original = $this->contentsOf($this->project->absolute($file));
        $mutated = $this->contentsOf($copy);

        if (! $original instanceof Contents || ! $mutated instanceof Contents) {
            return $this->judged($mutant, Outcome::unjudged(self::MISSING), $trial->limit());
        }

        $source = Source::read($file, $original, test: false);
        $symbol = $source->symbolAt($source->changedAt($mutated));

        if ($symbol instanceof Executable) {
            return $mutant;
        }

        $choice = $selector->choose($symbol, $file);
        $first = $choice->first($file);
        $outcome = $first instanceof Outcome ? $first : $trial->of($first, $file, $copy);
        $then = $choice->then();

        return $this->judged(
            $mutant,
            $outcome->leftAlive() && count($then) > 0 ? $trial->of($then, $file, $copy) : $outcome,
            $trial->limit(),
        );
    }

    /** The mutant as an outcome judges it, with the limit Pest allowed each mutant where it timed out. */
    private function judged(Mutant $mutant, Outcome $outcome, Seconds|Unmeasured $limit): Mutant
    {
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

    private function contentsOf(string $path): Contents|false
    {
        $text = is_file($path) ? file_get_contents($path) : false;

        return is_string($text) ? Contents::of($text) : false;
    }
}
