<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;
use function min;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\MutantChecks;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\Analysis\PreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckable;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckables;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Analysis\Rejections;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use Psr\Clock\ClockInterface;

/**
 * Static analysis's check of a run's mutants before their tests (ADR-0020,
 * decisions 11 and 12). Of the mutants a runner offers, it checks those
 * `PreCheck` says pay, by what the ledgers and this run have learned of
 * their mutators, side by side, against the originals' findings from the
 * shard's one warm-up, or, for a runner that prints its mutants, the
 * print's, where that analyses as the file does. A mutant with an error its
 * original does not have is rejected, and never runs. Each check teaches its
 * mutator's rejection rate and the time a check takes, which the shard's
 * results carry to the ledger. A check that cannot run, or is out of the
 * analyser's scope, leaves its mutant to its tests.
 */
final class PreChecking implements PreChecker
{
    /** What this shard's checks before the tests have taught. */
    private AnalyserHistory $learned;

    public function __construct(
        private readonly Adapters $adapters,
        private readonly ClockInterface $clock,
        private readonly Deadline|Unlimited $deadline,
        private readonly Seconds $perCheck,
        private readonly AnalyserHistory $known,
        private readonly AnalyserWarmUp $warmUp,
        private readonly Stopwatch $stopwatch,
        private readonly PreCheck $policy,
    ) {
        $this->learned = AnalyserHistory::of($known->analyser());
    }

    public function rejected(PreCheckables $mutants, ProcessCount $side): Rejections
    {
        $checker = $this->adapters->checker;
        $identity = $this->adapters->analyser;
        $chosen = $this->chosen($mutants);

        if (
            $checker instanceof NoAnalyser
            || ! $identity instanceof AnalyserIdentity
            || $chosen === []
            || ($this->deadline instanceof Deadline && $this->deadline->hasPassed($this->clock->now()))
        ) {
            return Rejections::none();
        }

        $from = $this->stopwatch->now();
        $warm = $this->warmUp->of($checker, $identity, $this->adapters, $this->clock);
        $rejections = $warm instanceof WarmedUp ? $this->checked($warm, $chosen, $side) : Rejections::none();
        $this->stopwatch->handled(Step::StaticCheck, $from, count($chosen));

        return $rejections;
    }

    /** What the checks before the tests taught, for the shard's results to carry to the ledger. */
    public function checks(): SurvivorChecks
    {
        return SurvivorChecks::none()->timing($this->learned);
    }

    /**
     * The mutants whose check pays, by what the ledgers and this run have
     * learned of their mutators.
     *
     * @return list<PreCheckable>
     */
    private function chosen(PreCheckables $mutants): array
    {
        $history = $this->known->plus($this->learned);
        $chosen = [];

        foreach ($mutants as $mutant) {
            if ($this->policy->pays($history, $mutant->mutant()->mutation(), $mutant->tests())) {
                $chosen[] = $mutant;
            }
        }

        return $chosen;
    }

    /**
     * The chosen mutants checked side by side, each against its original's
     * findings, and each rejected by an error its original does not have.
     *
     * @param list<PreCheckable> $chosen
     */
    private function checked(WarmedUp $warm, array $chosen, ProcessCount $side): Rejections
    {
        $baselines = new PrintBaselines($this->adapters, $warm, $this->perCheck)->of($chosen, $side);
        $checks = [];
        $judged = [];

        foreach ($chosen as $mutant) {
            $baseline = $baselines->baseline($mutant);
            $check = $baseline instanceof Findings ? $this->written($warm, $mutant) : $baseline;

            if ($check instanceof MutantCheck) {
                $checks[] = $check;
                $judged[] = [$mutant, $baseline];
            }
        }

        if ($checks === []) {
            return Rejections::none();
        }

        $started = $this->clock->now();
        $batch = MutantChecks::of(...$checks);
        $answers = [...$warm->checker->checks($batch, $side)->padded($batch)];
        $took = Seconds::between($started, $this->clock->now());
        $each = Seconds::of($took->seconds() * min($side->count(), count($checks)) / count($checks));

        foreach ($checks as $check) {
            $this->adapters->project->remove($check->mutant());
        }

        return $this->answered($warm, $judged, $answers, $each);
    }

    /**
     * A mutant written where its check reads it, with the dependents it can
     * break where the analyser reads them; or why it cannot be written.
     */
    private function written(WarmedUp $warm, PreCheckable $mutant): MutantCheck|CannotJudge
    {
        $id = $mutant->mutant()->id();
        $file = $mutant->mutant()->location()->file();
        $text = $mutant->checkable()->mutant();
        $written = $this->adapters->project->write(Workspace::checkedMutant($id), $text);
        $check = MutantCheck::of($file, Workspace::checkedMutant($id))
            ->within($this->perCheck)
            ->withholding($this->adapters->withheld);

        return match (true) {
            $written instanceof CannotJudge => $written,
            $warm->checker->readsDependents() => $check->withDependents(
                PrintBaselines::dependents($this->adapters, $warm, $mutant),
            ),
            default => $check,
        };
    }

    /**
     * Each checked mutant rejected by a new error, or passed, with what its
     * check taught; one whose check could not run, or was out of scope,
     * teaches only the time it took.
     *
     * @param list<array{PreCheckable, Findings}>   $judged
     * @param list<Findings|OutOfScope|CannotJudge> $answers one in the place of each judged mutant's check
     */
    private function answered(WarmedUp $warm, array $judged, array $answers, Seconds $each): Rejections
    {
        $rejections = Rejections::none();

        foreach ($judged as $at => [$mutant, $baseline]) {
            $answer = $answers[$at];
            $mutation = $mutant->mutant()->mutation();
            $errors = $answer instanceof Findings ? [...$answer->newErrors($baseline)] : [];
            $this->learned = match (true) {
                ! $answer instanceof Findings => $this->learned->checked($each),
                $errors === [] => $this->learned->passed($mutation, $each),
                default => $this->learned->rejected($mutation, $each),
            };
            $rejections = $errors === []
                ? $rejections
                : $rejections->with($mutant->mutant()->id(), Rejection::by($warm->identity->analyser(), $errors[0]));
        }

        return $rejections;
    }
}
