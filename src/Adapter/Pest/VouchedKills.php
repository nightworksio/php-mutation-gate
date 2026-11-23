<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function array_values;

use Closure;

use function count;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Share;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * What a patched run's narrowed kills come to (ADR-0142): each kill whose
 * order is known vouched for by a replay of its run, unmutated (see
 * PrefixReplays), and unjudged where the replay does not let it stand; each
 * other kill its control cannot vouch for run again with every test file
 * (see NarrowedKills) within the time left, or unjudged where none is left,
 * a kill the run again confirms standing only where its control passes.
 *
 * @internal
 */
final readonly class VouchedKills
{
    /** Why such a mutant is unjudged where the run again made no mutant with its id. */
    private const string NOT_MADE_TO_CONFIRM = 'Run again with every test file, Pest made no mutant with this id.';

    /**
     * @param Closure(): (Seconds|Unlimited)                                 $left     the time the run has left
     * @param Closure(): (Covering|CannotJudge)                              $covering the coverage the run's mutants
     *                                                                                 were selected by
     * @param Closure(MutationRequest, string...): (MutationResult|CannotJudge) $made the mutants with these
     *                                                                                native ids made again with
     *                                                                                every test file
     */
    public function __construct(
        private Project $project,
        private Patching $patching,
        private Shell $tail,
        private Remembered $remembered,
        private Closure $left,
        private Closure $covering,
        private Closure $made,
    ) {
    }

    /**
     * The result, each narrowed kill whose order is known vouched for by a
     * replay of its run, unmutated (see PrefixReplays), and unjudged where
     * the replay does not let it stand; each other narrowed kill its control
     * cannot vouch for run again with every test file (see NarrowedKills),
     * with the time each took. The controls of the kills run again are read
     * off this run's coverage before the run again replaces it.
     */
    public function of(
        MutationResult $result,
        MutationRequest $request,
        string $results,
        Laps $laps,
    ): MutationResult|CannotJudge {
        $kills = NarrowedKills::in($result, $results, $this->project, $request->search()->killControls());
        $from = $laps->now();
        $replayed = $this->unvouched($kills, $request, $results);
        $doubtful = $this->doubted($kills, $request, $results, $replayed);
        $baselines = StepTimes::of($laps->lap(Step::Baselines, $from, $kills->sets()));
        $controls = Controls::of(
            $this->project,
            $this->patching,
            $doubtful,
            $request->judgedBy(),
            $this->covering,
        );

        $vouched = $result->withMutants(FoundAgain::replacing($result->mutants(), $replayed->unjudged()))
            ->withSteps($baselines);

        return $this->confirmed($vouched, $request, $results, $controls, $laps);
    }

    /** What the replays, unmutated, leave of a narrowed run's kills whose order is known (see PrefixReplays). */
    private function unvouched(
        NarrowedKills $kills,
        MutationRequest $request,
        string $results,
    ): ReplayedKills {
        return $kills->unvouched(
            /**
             * @param  non-empty-list<PrefixReplay> $replays
             * @return list<ReplayVerdict>
             */
            fn(array $replays): array => new PrefixReplays($this->project, $this->tail, $this->remembered)
                ->verdicts($replays, $request, ($this->left)(), $results),
        );
    }

    /** A narrowed run's kills that must run again with every test file before they count. */
    private function doubted(
        NarrowedKills $kills,
        MutationRequest $request,
        string $results,
        ReplayedKills $replayed,
    ): Mutants {
        return $kills->doubted(
            $request->judgedBy(),
            /**
             * @param  non-empty-list<Control> $controls
             * @return list<bool>
             */
            fn(array $controls): array => array_map(
                static fn(ControlRun $run): bool => $run->end() === ControlEnd::Passed,
                new AloneRuns($this->project, $this->tail, $this->remembered)
                    ->ended($controls, $request, ($this->left)(), $results),
            ),
            $replayed,
        );
    }

    /**
     * The result, each doubtful kill run again with every test file, within
     * the time left since the run began, or unjudged where none is left or
     * the deadline stopped the run again before it made them (ADR-0300); a
     * kill the run again confirms stands only where its control passes.
     */
    private function confirmed(
        MutationResult $result,
        MutationRequest $request,
        string $results,
        Controls $controls,
        Laps $laps,
    ): MutationResult|CannotJudge {
        $doubtful = $controls->doubtful();

        if (count($doubtful) === 0) {
            return $result;
        }

        $from = $laps->now();
        $left = ($this->left)();
        $unvouched = MutationResult::skippingNone(
            FoundAgain::among($doubtful, Mutants::none(), OutOfTime::BeforeVouching->reason()),
        );
        $within = $this->kept($request, $left);
        $again = $left instanceof Seconds && $left->seconds() <= 0.0
            ? $unvouched
            : $this->again($doubtful, $this->confirming($request, $within));
        $again = $again instanceof CannotJudge && $again->isAtTheDeadline() ? $unvouched : $again;
        $runs = new AloneRuns($this->project, $this->tail, $this->remembered);
        $again = $again instanceof CannotJudge
            ? $again
            : $again->withMutants(
                $controls->applied($again->mutants(), $runs, $request, $this->remaining($within, $left), $results),
            );

        return $again instanceof CannotJudge ? $again : $result
            ->withMutants(FoundAgain::replacing($result->mutants(), $again->mutants()))
            ->withEvidence($again->evidence())
            ->withSteps(StepTimes::of($laps->lap(Step::Confirmation, $from, count($doubtful))));
    }

    /**
     * The time the run again and its controls have: the share of the
     * request's time it kept back for them, and no more than is left.
     */
    private function kept(MutationRequest $request, Seconds|Unlimited $left): Seconds|Unlimited
    {
        $kept = $request->search()->confirmShare()->taken($request->deadline());

        return $kept instanceof Seconds && $left instanceof Seconds
            ? Seconds::of(min($kept->seconds(), $left->seconds()))
            : $left;
    }

    /**
     * What is left of the time the confirmation may take, of which this much was left as it began: that time less
     * what has passed since, and no limit where it has none.
     */
    private function remaining(Seconds|Unlimited $within, Seconds|Unlimited $began): Seconds|Unlimited
    {
        $now = ($this->left)();

        return $within instanceof Seconds && $began instanceof Seconds && $now instanceof Seconds
            ? Seconds::of(max(0.0, $within->seconds() - ($began->seconds() - $now->seconds())))
            : $now;
    }

    /**
     * The request the doubtful kills run again by: within the time left, all
     * of it its mutants' own, in the pool the tail runs in.
     */
    private function confirming(MutationRequest $request, Seconds|Unlimited $left): MutationRequest
    {
        $whole = $request->searching($request->search()->confirmingWithin(Share::whole()));

        return ($left instanceof Seconds ? $whole->within($left) : $whole)->across($request->pool()->tail());
    }

    /**
     * These mutants made again over their files with their mutators, each
     * matched to the one asked for, with the evidence of each kill.
     */
    private function again(Mutants $mutants, MutationRequest $request): MutationResult|CannotJudge
    {
        $files = [];
        $mutators = [];
        $natives = [];

        foreach ($mutants as $mutant) {
            $files[] = $mutant->location()->file();
            $mutators[$mutant->mutation()->mutator()] = $mutant->mutation()->mutator();
            $natives[] = $mutant->nativeId();
        }

        $result = ($this->made)(
            $request->narrowedTo(
                Paths::of(...$files),
                $request->narrowing()->toMutators(Mutators::named(...array_values($mutators))),
            ),
            ...$natives,
        );

        return $result instanceof CannotJudge ? $result : MutationResult::skippingNone(
            FoundAgain::among($mutants, $result->mutants(), Reason::that(self::NOT_MADE_TO_CONFIRM)),
        )->withEvidence(FoundAgain::evidenceAmong($mutants, $result));
    }
}
