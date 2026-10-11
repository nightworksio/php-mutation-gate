<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;

use Closure;

use function count;
use function dirname;

use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * One Pest mutation run, from a fresh results file to the gate's records: a
 * patched shard opens on the canary group with the map of its own files the
 * plan handed over, which the run then reads its mutants' covering tests
 * from, and judges its mutants on lines that are not executable by the plan's
 * whole map; every other run reads its own opening map once, for both.
 */
final readonly class MutationRun
{
    private const string BY_GROUP_ALONE
        = 'Pest selects held tests by the holds: groups its plugin adds for #[Holds], not by the filter %s.';

    private const string COMMA = "Pest's --path and --ignore split on commas, so Pest cannot mutate %s less %s.";

    private const string NOT_PATCHED
        = 'pest.patch is on, but pest-plugin-mutate in %s is not patched. Run mutation-gate pest:patch.';

    private const string EMPTY_CANARY = 'pest.patch is on, but the canary group %s holds no test. Add one.';

    /** Why a narrowed run's doubtful kill is unjudged where no time is left to run it again. */
    private const string NO_TIME_TO_CONFIRM
        = "Killed where its covering tests' files alone cannot vouch for the kill; no time is left to run them all.";

    /** Why such a mutant is unjudged where the run again made no mutant with its id. */
    private const string NOT_MADE_TO_CONFIRM = 'Run again with every test file, Pest made no mutant with this id.';

    /** Where the map another job handed over is written again for this job's Pest, beside the results. */
    private const string SHARED_MAP = '%s/shared.coverage.php';

    /**
     * @param Closure(Withheld, Suites): (TestListing|CannotJudge) $listing the suites' tests and groups,
     *                                                                as the runner lists them
     * @param list<string>                            $only   the native ids of the only mutants a patched run
     *                                                        makes, or none for every mutant it finds
     */
    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private Patching $patching,
        private Remembered $remembered,
        private Closure $listing,
        private LimitBounds $bounds,
        private array $only = [],
        private Clock $clock = new WallClock(),
        private bool $whole = false,
        private Bridges $bridges = new Bridges(),
        private PreChecker $preChecker = new NoPreCheck(),
    ) {
    }

    /**
     * This run, its mutants checked by static analysis before their tests
     * where pest-plugin-mutate is patched (see HandOff).
     */
    public function checkingWith(PreChecker $preChecker): self
    {
        return clone($this, ['preChecker' => $preChecker]);
    }

    /**
     * This run, making only the mutants with these native ids where
     * pest-plugin-mutate is patched; unpatched, it makes every mutant of its
     * files and mutators, and the caller matches back the ones it asked for.
     */
    public function only(string ...$nativeIds): self
    {
        return clone($this, ['only' => array_values($nativeIds)]);
    }

    /** This run, in which a patched run allows no mutant more than this long: the most a run again asks for. */
    public function upTo(Seconds $most): self
    {
        return clone($this, ['bounds' => $this->bounds->upToInstead($most)]);
    }

    /**
     * This run, in which each mutant's own run loads every test file, as
     * Pest ships it, where a patched run narrows it.
     */
    public function whole(): self
    {
        return clone($this, ['whole' => true]);
    }

    /**
     * Every mutant of the requested files, where there are any to mutate:
     * Pest's `--path` never names none. Where a patched run narrowed each
     * mutant's own run to the test files its covering tests need, a kill
     * those files alone cannot vouch for (see NarrowedKills) runs again with
     * every test file before it counts, within the time left, or is unjudged
     * where none is.
     */
    public function of(MutationRequest $request): MutationResult|CannotJudge
    {
        if (count($request->files()) === 0 || $this->bridges->appliesNone($request->narrowing()->mutators())) {
            return MutationResult::of(Mutants::none(), 0);
        }

        $started = $this->clock->seconds();
        $results = $this->project->freshResults();

        if ($results instanceof CannotJudge) {
            return $results;
        }

        $laps = Laps::since(fn(): Seconds => Seconds::of($this->clock->seconds()), Seconds::of($started));
        $from = $laps->now();
        $shared = $this->shared($request);
        $read = $shared instanceof CoverageMap ? StepTimes::of($laps->lap(Step::Coverage, $from)) : StepTimes::none();
        $result = $shared instanceof CannotJudge ? $shared : $this->ran($request, $results, $shared, $started, $laps);
        $result = $result instanceof CannotJudge ? $result : $result->withStepsBefore($read);

        return match (true) {
            $shared instanceof CannotJudge, $result instanceof CannotJudge, ! $this->narrows() => $result,
            default => $this->vouched($result, $request, $results, $shared, $started, $laps),
        };
    }

    /**
     * The result, each narrowed kill whose order is known vouched for by a
     * replay of its run, unmutated (see PrefixReplays), and unjudged where
     * the replay does not let it stand; each other narrowed kill its control
     * cannot vouch for run again with every test file (see NarrowedKills),
     * with the time each took. The controls of the kills run again are read
     * off this run's coverage before the run again replaces it.
     */
    private function vouched(
        MutationResult $result,
        MutationRequest $request,
        string $results,
        CoverageMap|Unshared $shared,
        float $started,
        Laps $laps,
    ): MutationResult|CannotJudge {
        $kills = NarrowedKills::in($result, $results, $this->project);
        $from = $laps->now();
        $unvouched = $this->unvouched($kills, $request, $results, $started);
        $doubtful = $this->doubted($kills, $request, $results, $started);
        $baselines = StepTimes::of($laps->lap(Step::Baselines, $from, $kills->sets()));
        $controls = Controls::of(
            $this->project,
            $doubtful,
            $request->judgedBy(),
            fn(): Covering|CannotJudge => $this->coveringOf($shared, $results),
        );

        $replayed = $result->withMutants(FoundAgain::replacing($result->mutants(), $unvouched))->withSteps($baselines);

        return $this->confirmed($replayed, $request, $results, $started, $controls, $laps);
    }

    /** A narrowed run's kills whose replay, unmutated, does not let them stand, unjudged (see PrefixReplays). */
    private function unvouched(NarrowedKills $kills, MutationRequest $request, string $results, float $started): Mutants
    {
        return $kills->unvouched(
            /**
             * @param  non-empty-list<PrefixReplay> $replays
             * @return list<ReplayVerdict>
             */
            fn(array $replays): array => new PrefixReplays($this->project, $this->shell, $this->remembered)
                ->verdicts($replays, $request, $this->left($request, $started), $results),
        );
    }

    /** A narrowed run's kills that must run again with every test file before they count. */
    private function doubted(NarrowedKills $kills, MutationRequest $request, string $results, float $started): Mutants
    {
        return $kills->doubted(
            $request->judgedBy(),
            /**
             * @param  non-empty-list<Control> $controls
             * @return list<bool>
             */
            fn(array $controls): array => new AloneRuns($this->project, $this->shell, $this->remembered)
                ->pass($controls, $request, $this->left($request, $started), $results),
        );
    }

    /** The coverage this run's mutants were selected by: the map handed over, or the run's own. */
    private function coveringOf(CoverageMap|Unshared $shared, string $results): Covering|CannotJudge
    {
        return $shared instanceof CoverageMap
            ? new HandedOver($shared, $this->project)
            : CoverageFile::at(Recorder::coverageBeside($results));
    }

    private function ran(
        MutationRequest $request,
        string $results,
        CoverageMap|Unshared $shared,
        float $started,
        Laps $laps,
    ): MutationResult|CannotJudge {
        $command = Plan::handedOver($this->project, $request, $this->commandFor($request, $results, $shared));
        $scan = MemoryScan::beside($this->project, $results, $request->memory(), $this->files);

        if ($command instanceof CannotJudge || $scan instanceof CannotJudge) {
            return $command instanceof CannotJudge ? $command : $scan;
        }

        $command = $scan->onto($command);
        $bounds = $request->pool()->bounding($this->bounds);
        $from = $laps->now();
        $handOff = $this->handOff($request, $results, $shared);
        $told = new Told($this->project, $this->patching, $this->only, $this->narrows())
            ->of($request, $results, $handOff instanceof HandOff, $bounds);
        $ran = $this->shell->run($command->with($told), $handOff instanceof HandOff ? $handOff : new Unwatched());
        $mutation = $laps->lap(Step::Mutation, $from);
        $scan->remove();
        $coverage = $this->coveringOf($shared, $results);
        $opensOn = $this->patching->opensOn($request->judgedBy(), $shared instanceof CoverageMap);
        $left = fn(): Seconds|Unlimited => $this->left($request, $started);
        $opening = OpeningIssues::of($this->shell, $this->project, $request, $opensOn, $left, $results);
        $memory = $request->memory();
        $interpretation = new Interpretation(
            $this->project,
            $this->patching,
            $memory,
            $this->bridges,
            $opening,
            $handOff,
        );
        $from = $laps->now();
        $result = $interpretation->of($ran, $results, $coverage);
        $reading = $laps->lap(Step::Reading, $from);

        return $result instanceof CannotJudge || $coverage instanceof CannotJudge
            ? $result
            : new Trials($this->project, $this->shell, $this->files, $bounds, $this->remembered)->of(
                $result->withSteps(StepTimes::of(StepTime::counted($mutation, count($result->mutants())), $reading)),
                $request,
                $results,
                $coverage,
                $shared,
                $laps,
            );
    }

    /**
     * The hand-off of the run's mutants to static analysis before their
     * tests, where pest-plugin-mutate is patched and a pre-checker is given;
     * none otherwise, and Pest runs every mutant.
     */
    private function handOff(MutationRequest $request, string $results, CoverageMap|Unshared $shared): HandOff|NotGiven
    {
        return $this->patching->isOn() && ! $this->preChecker instanceof NoPreCheck ? new HandOff(
            $this->project,
            $results,
            $this->preChecker,
            $request->pool()->processes(),
            fn(): Covering|CannotJudge => $this->coveringOf($shared, $results),
            $this->bridges,
        ) : NotGiven::value();
    }

    /** Whether each mutant's own run loads only the test files its covering tests need. */
    private function narrows(): bool
    {
        return $this->patching->isOn() && ! $this->whole;
    }

    /**
     * The result, each doubtful kill run again with every test file, within
     * the time left since the run began, or unjudged where none is left; a
     * kill the run again confirms stands only where its control passes.
     */
    private function confirmed(
        MutationResult $result,
        MutationRequest $request,
        string $results,
        float $started,
        Controls $controls,
        Laps $laps,
    ): MutationResult|CannotJudge {
        $doubtful = $controls->doubtful();

        if (count($doubtful) === 0) {
            return $result;
        }

        $from = $laps->now();
        $left = $this->left($request, $started);
        $unconfirmed = Reason::that(self::NO_TIME_TO_CONFIRM);
        $again = $left instanceof Seconds && $left->seconds() <= 0.0
            ? MutationResult::of(FoundAgain::among($doubtful, Mutants::none(), $unconfirmed), 0)
            : $this->whole()->again($doubtful, $left instanceof Seconds ? $request->within($left) : $request);
        $runs = new AloneRuns($this->project, $this->shell, $this->remembered);
        $again = $again instanceof CannotJudge
            ? $again
            : $again->withMutants(
                $controls->applied($again->mutants(), $runs, $request, $this->left($request, $started), $results),
            );

        return $again instanceof CannotJudge ? $again : $result
            ->withMutants(FoundAgain::replacing($result->mutants(), $again->mutants()))
            ->withEvidence($again->evidence())
            ->withSteps(StepTimes::of($laps->lap(Step::Confirmation, $from)));
    }

    /** The time left of the request's deadline since the run began. */
    private function left(MutationRequest $request, float $started): Seconds|Unlimited
    {
        $deadline = $request->deadline();

        return $deadline instanceof Seconds
            ? Seconds::of($deadline->seconds() - ($this->clock->seconds() - $started))
            : $deadline;
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
            $files[$mutant->location()->file()->value()] = $mutant->location()->file();
            $mutators[$mutant->mutation()->mutator()] = $mutant->mutation()->mutator();
            $natives[] = $mutant->nativeId();
        }

        $result = $this->only(...$natives)->of(
            $request->narrowedTo(
                Paths::of(...array_values($files)),
                $request->narrowing()->toMutators(Mutators::named(...array_values($mutators))),
            ),
        );

        return $result instanceof CannotJudge ? $result : MutationResult::of(
            FoundAgain::among($mutants, $result->mutants(), Reason::that(self::NOT_MADE_TO_CONFIRM)),
            0,
        )->withEvidence(FoundAgain::evidenceAmong($mutants, $result));
    }

    private function commandFor(
        MutationRequest $request,
        string $results,
        CoverageMap|Unshared $shared,
    ): Command|CannotJudge {
        $judgedBy = $request->judgedBy();
        $files = PathList::of($request->files());
        $leftOut = PathList::of($request->leftOut());

        return match (true) {
            $judgedBy instanceof Filter => CannotJudge::because(sprintf(self::BY_GROUP_ALONE, $judgedBy->pattern())),
            $files->holdsAComma() || $leftOut->holdsAComma() => CannotJudge::because(
                sprintf(self::COMMA, $files->joined(', '), $leftOut->joined(', ')),
            ),
            default => $this->bridges->loading(
                $this->project,
                $this->opened(
                    Invocation::installedIn($this->project->vendor())
                        ->mutation($request, $judgedBy, $results, $this->bridges),
                    $results,
                    $shared,
                ),
            ),
        };
    }

    /** A run that opens on the canary group with the map another job handed over, where it has one. */
    private function opened(Command $command, string $results, CoverageMap|Unshared $shared): Command
    {
        if (! $shared instanceof CoverageMap) {
            return $command;
        }

        $map = sprintf(self::SHARED_MAP, dirname($results));
        $this->remembered->writeOnce($map, fn() => SharedCoverage::write($shared, $this->project, $map));

        $canary = $this->patching->canary();

        return $command->with([
            GateVariable::SharedCoverage->value => $map,
            GateVariable::SuiteSeconds->value => sprintf('%F', SharedCoverage::seconds($shared)),
            ...($canary instanceof Group ? [GateVariable::Canary->value => $canary->name()] : []),
        ]);
    }

    /**
     * The shard's own map the plan handed over, where a patched shard opens
     * on the canary group, why it cannot, or none where the run opens on its
     * own suite.
     */
    private function shared(MutationRequest $request): CoverageMap|Unshared|CannotJudge
    {
        $handed = $request->coverage();

        if (! $this->patching->isOn() || ! $handed instanceof Handed || ! $request->judgedBy() instanceof WholeSuite) {
            return Unshared::Coverage;
        }

        $directory = $handed->own();

        $refusal = $this->refusal($request);

        $reading = fn(): CoverageMap|CannotJudge => SharedCoverage::in($this->project, $directory);

        return $refusal instanceof CannotJudge ? $refusal : $this->remembered->map($directory, $reading);
    }

    /** Why a shard cannot open on the canary group among the suites its opening run runs, if it cannot. */
    private function refusal(MutationRequest $request): TestListing|CannotJudge
    {
        $vendor = $this->project->absolute($this->project->vendor());

        if (! $this->remembered->patched(static fn(): bool => Patch::isAppliedIn($vendor))) {
            return CannotJudge::because(sprintf(self::NOT_PATCHED, $this->project->vendor()->value()));
        }

        $canary = $this->patching->canary();
        $opensOn = $this->patching->opensOn($request->judgedBy(), handedAMap: true);
        $listing = ($this->listing)($request->withheld(), $request->narrowing()->suitesFor($opensOn));

        return $listing instanceof CannotJudge || ! $canary instanceof Group || $listing->groups()->has($canary)
            ? $listing
            : CannotJudge::because(sprintf(self::EMPTY_CANARY, $canary->name()));
    }
}
