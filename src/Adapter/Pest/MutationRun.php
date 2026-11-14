<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;

use Closure;

use function count;
use function dirname;

use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamReach;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\OpcacheBlacklist;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\PackageFiles;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Preloaded;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;
use NightWorksIO\MutationGate\Core\Runner\RunExtension;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * One Pest mutation run, from a fresh results file to the gate's records: a
 * patched shard opens on the canary group with the map of its own files the
 * plan handed over, which the run then reads its mutants' covering tests
 * from, and judges its mutants on lines that are not executable by the plan's
 * whole map; every other run reads its own opening map once, for both.
 *
 * @internal
 */
final readonly class MutationRun
{
    private const string BY_GROUP_ALONE
        = 'Pest selects held tests by the holds: groups its plugin adds for #[Holds], not by the filter %s.';

    private const string COMMA = "Pest's --path and --ignore split on commas, so Pest cannot mutate %s less %s.";

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
        private PackageFiles $packages,
        private Patching $patching,
        private Remembered $remembered,
        private Closure $listing,
        private LimitBounds $bounds,
        private array $only = [],
        private Clock $clock = new WallClock(),
        private bool $whole = false,
        private Bridges $bridges = new Bridges(),
        private PreChecker $preChecker = new NoPreCheck(),
        private RunExtension|NotGiven $extension = new NotGiven(),
    ) {
    }

    /**
     * This run, its mutants checked by static analysis before their tests
     * where pest-plugin-mutate is patched (see HandOff).
     */
    public function checkingWith(PreChecker $preChecker): self
    {
        return new self(
            $this->project,
            $this->shell,
            $this->files,
            $this->packages,
            $this->patching,
            $this->remembered,
            $this->listing,
            $this->bounds,
            $this->only,
            $this->clock,
            $this->whole,
            $this->bridges,
            $preChecker,
            $this->extension,
        );
    }

    /**
     * This run, making only the mutants with these native ids where
     * pest-plugin-mutate is patched; unpatched, it makes every mutant of its
     * files and mutators, and the caller matches back the ones it asked for.
     */
    public function only(string ...$nativeIds): self
    {
        return new self(
            $this->project,
            $this->shell,
            $this->files,
            $this->packages,
            $this->patching,
            $this->remembered,
            $this->listing,
            $this->bounds,
            array_values($nativeIds),
            $this->clock,
            $this->whole,
            $this->bridges,
            $this->preChecker,
            $this->extension,
        );
    }

    /** This run, in which a patched run allows no mutant more than this long: the most a run again asks for. */
    public function upTo(Seconds $most): self
    {
        return new self(
            $this->project,
            $this->shell,
            $this->files,
            $this->packages,
            $this->patching,
            $this->remembered,
            $this->listing,
            $this->bounds->upToInstead($most),
            $this->only,
            $this->clock,
            $this->whole,
            $this->bridges,
            $this->preChecker,
            $this->extension,
        );
    }

    /**
     * This run, in which each mutant's own run loads every test file, as
     * Pest ships it, where a patched run narrows it.
     */
    public function whole(): self
    {
        return new self(
            $this->project,
            $this->shell,
            $this->files,
            $this->packages,
            $this->patching,
            $this->remembered,
            $this->listing,
            $this->bounds,
            $this->only,
            $this->clock,
            whole: true,
            bridges: $this->bridges,
            preChecker: $this->preChecker,
            extension: $this->extension,
        );
    }

    /**
     * Every mutant of the requested files, where there are any to mutate:
     * Pest's `--path` never names none. Where a patched run narrowed each
     * mutant's own run to the test files its covering tests need, a kill
     * those files alone cannot vouch for (see NarrowedKills) runs again with
     * every test file before it counts, within the time left, or is unjudged
     * where none is; under `pest.narrowedKills: trust` it counts as it is
     * (ADR-0142). Under `pest.preloaded: fresh`, each mutant its own run left
     * unjudged as it had loaded the mutant's file first is judged again in a
     * fresh run (see FreshRuns), its covering tests read off this run's
     * coverage before a run again replaces it; where that cannot be read, it
     * stays unjudged.
     */
    public function of(MutationRequest $request): MutationResult|CannotJudge
    {
        if (count($request->files()) === 0 || $this->bridges->appliesNone($request->narrowing()->mutators())) {
            return MutationResult::skippingNone(Mutants::none());
        }

        $started = $this->clock->seconds();
        $results = $this->project->freshResults();

        if ($results instanceof CannotJudge) {
            return $results;
        }

        $laps = Laps::since(fn(): Seconds => Seconds::of($this->clock->seconds()), Seconds::of($started));
        $opened = $this->shardMap()->of($request, $laps);
        $shared = $opened->map();
        $result = $shared instanceof CannotJudge ? $shared : $this->ran($request, $results, $shared, $started, $laps);

        return $result instanceof CannotJudge
            ? $result
            : $this->judged($result->withStepsBefore($opened->steps()), $request, $results, $shared, $started, $laps);
    }

    /**
     * What a run's mutants come to once its narrowed kills are vouched for,
     * and each mutant its own run left unjudged as it had loaded the mutant's
     * file first is judged again in a fresh run.
     */
    private function judged(
        MutationResult $result,
        MutationRequest $request,
        string $results,
        CoverageMap|Unshared $shared,
        float $started,
        Laps $laps,
    ): MutationResult|CannotJudge {
        $placement = $this->project->placement();
        $coverage = $placement instanceof MutantPlacement
            && $this->patching->steps()->preloaded() === Preloaded::Fresh
            && FreshRuns::findAny($result->mutants())
            ? $this->coveringOf($shared, $results)
            : NotGiven::value();
        $vouched = ! $this->narrows() || $this->patching->steps()->narrowedKills() === NarrowedKillTrust::Trust
            ? $result
            : new VouchedKills(
                $this->project,
                $this->patching,
                $this->tail($request),
                $this->remembered,
                fn(): Seconds|Unlimited => $this->left($request, $started),
                fn(): Covering|CannotJudge => $this->coveringOf($shared, $results),
                fn(MutationRequest $again, string ...$natives): MutationResult|CannotJudge => $this->whole()
                    ->only(...$natives)
                    ->of($again),
            )->of($result, $request, $results, $laps);

        return $vouched instanceof MutationResult && $coverage instanceof Covering
            ? new FreshRuns(
                $this->project,
                $placement,
                $this->driven(),
                $this->files,
                $this->patching,
                $this->bounds,
            )->judged(
                $vouched,
                $request,
                $coverage,
                fn(): Seconds|Unlimited => $this->left($request, $started),
                $laps,
            )
            : $vouched;
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
        $told = $this->told($request, $results, $handOff, $bounds);
        $this->warmFor($request, $command, $results);
        $ran = $this->shell->run(
            $command->with($told),
            new MutantsProgress($results, $handOff instanceof HandOff ? $handOff : new Unwatched()),
        );
        $mutation = $laps->lap(Step::Mutation, $from);
        $scan->remove();
        $warned = $this->warned($request, $results);
        $coverage = $this->coveringOf($shared, $results);
        $opensOn = $this->patching->opensOn($request->judgedBy(), $shared instanceof CoverageMap);
        $left = fn(): Seconds|Unlimited => $this->left($request, $started);
        $opening = OpeningIssues::of($this->driven(), $this->project, $request, $opensOn, $left, $results);
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

        if ($result instanceof CannotJudge || $coverage instanceof CannotJudge) {
            return $result;
        }

        $read = $result
            ->withSteps(StepTimes::of(StepTime::counted($mutation, count($result->mutants())), $reading))
            ->withWarnings($warned);

        return $this->patching->steps()->on(Toggle::PestTrials)
            ? new Trials(
                $this->project,
                $this->tail($request),
                $this->files,
                $bounds,
                $this->remembered,
                $this->patching->steps()->on(Toggle::PestShardScan),
            )->of($read, $request, $results, $coverage, $shared, $laps)
            : $read;
    }

    /**
     * The variables a run of this request is started with: what it tells the patched plugin, what it tells its
     * streams of Outbreak's extension, the coverage drivers it turns off, and, where its streams record lines with
     * pcov, what pcov collects from there.
     *
     * @return array<string, string>
     */
    private function told(
        MutationRequest $request,
        string $results,
        HandOff|NotGiven $handOff,
        LimitBounds $bounds,
    ): array {
        $recording = $request->narrowing()->recording();
        $withPcov = ToldExtension::recordsWithPcov($this->extension, $recording);

        return [
            ...new Told($this->project, $this->patching, $this->only, $this->narrows())
                ->of($request, $results, $handOff instanceof HandOff, $bounds),
            ...ToldExtension::of($this->extension, $recording),
            ...CoverageDrivers::of($this->patching, $withPcov),
            ...$withPcov ? StreamReach::told(
                PcovReach::under($this->project->root(), $this->project->vendor())->keeping(Schemata::inVendor()),
            ) : [],
        ];
    }

    /**
     * The directory the run's warm workers share, made ready for it: what they keep out of opcache, where they
     * compile through it, and how they boot.
     */
    private function warmFor(MutationRequest $request, Command $command, string $results): void
    {
        $warm = WarmDirectory::made($results);

        if ($this->patching->cachesInWorkers()) {
            OpcacheBlacklist::of($this->project, $request)->leftIn($warm);
        }

        WorkerBoot::of($this->project, $this->patching)->leftFor($request, $command, $this->narrows(), $warm);
    }

    /**
     * What the run's warm workers warned of: so far, where a shard's chunks keep them for the next chunk; once they
     * are cleared, otherwise.
     */
    private function warned(MutationRequest $request, string $results): Warnings
    {
        return $this->patching->keepsChunkWorkers()
            ? WarmDirectory::warnedSoFar($results)
            : WarmDirectory::cleared($results, $request->pool()->pace()->workerWait());
    }

    /**
     * The hand-off of the run's mutants to static analysis before their
     * tests, and of those it holds back until the others ran, where
     * pest-plugin-mutate is patched and a pre-checker is given or the run
     * holds subsumed mutants back; none otherwise, and Pest runs every
     * mutant in its own order.
     */
    private function handOff(MutationRequest $request, string $results, CoverageMap|Unshared $shared): HandOff|NotGiven
    {
        $held = $request->search()->held();

        $prover = $this->extension instanceof RunExtension
            ? $this->extension->prover(dirname($results))
            : NotGiven::value();

        return $this->patching->isOn() && HandOff::isWanted($this->preChecker, $held, $prover)
            ? new HandOff(
                $this->project,
                $results,
                $this->preChecker,
                $request->pool()->processes(),
                fn(): Covering|CannotJudge => $this->coveringOf($shared, $results),
                $this->bridges,
                $held,
                $prover,
                $this->patching->steps()->duplicates() === Duplicates::JudgeOnce,
            )
            : NotGiven::value();
    }

    /** Whether each mutant's own run loads only the test files its covering tests need, as `pest.narrow` says. */
    private function narrows(): bool
    {
        return $this->patching->isOn() && $this->patching->steps()->on(Toggle::PestNarrow) && ! $this->whole;
    }

    /** The time left of the request's deadline since the run began. */
    private function left(MutationRequest $request, float $started): Seconds|Unlimited
    {
        $deadline = $request->deadline();

        return $deadline instanceof Seconds
            ? Seconds::of($deadline->seconds() - ($this->clock->seconds() - $started))
            : $deadline;
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
                $this->shardMap()->opened(
                    Invocation::of($this->project)
                        ->mutation($request, $judgedBy, $results, $this->bridges),
                    $results,
                    $shared,
                    $request,
                ),
            ),
        };
    }

    /** The shell the request's verification tail runs Pest in (see TailShell). */
    private function tail(MutationRequest $request): Shell
    {
        return DriversSetShell::over(
            TailShell::of($this->shell, $this->project, $this->patching, $request),
            CoverageDrivers::of($this->patching, recordsWithPcov: false),
        );
    }

    /** The shell the run's other processes start in, with the coverage drivers a mutation run sets (ADR-0147). */
    private function driven(): Shell
    {
        return DriversSetShell::over($this->shell, CoverageDrivers::of($this->patching, recordsWithPcov: false));
    }

    private function shardMap(): ShardMap
    {
        return new ShardMap($this->project, $this->packages, $this->patching, $this->remembered, $this->listing);
    }
}
