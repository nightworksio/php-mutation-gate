<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactClaims;
use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactService;
use NightWorksIO\MutationGate\Adapter\GitHub\WatchedJob;
use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Adapter\Process\ErrorOutputLog;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreCheck;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Secrets;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Mutant\Unevidenced;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Plan\Batching;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PullQueue;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Proof\GateRelease;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\NoProcessLog;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doom;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Repository;

use function sprintf;

use Symfony\Component\HttpClient\HttpClient;

/**
 * `run --plan`: one shard of a plan, on the commit the plan was made on. Each
 * held unit runs alone against the tests that hold it, then the shard's other
 * units against the whole suite, reading the coverage map the plan handed it. The
 * shard leaves every mutant's record, each unit's key and what it measured,
 * or the runner's cannot judge, for the verdict. Each mutant whose time ran
 * out is never run again, and keeps the time its judging tests take on their
 * own, from the handed map, for timeout triage. Under
 * `tests.order: killers-first` each mutant's likely killers run first, by the
 * kill history the plan handed the shard beside its map: a shard handed none
 * orders its tests as though no test had killed anything yet, and one whose
 * history cannot be read does too, and warns of it. A pull request's shard
 * runs in chunks, and stops once a survivor makes its run certain to fail,
 * naming that survivor in its result (ADR-0008). Each kill's
 * evidence the runner gave is left beside its mutant, what a process printed
 * kept only where it holds no secret the gate withholds (ADR-0014).
 *
 * @internal
 */
final readonly class Running
{
    private const string NO_HEAD = 'The commit HEAD is at cannot be read, so the plan cannot be checked against it. %s';

    private const string UNREAD_HISTORY = 'Shard %d ran its tests without the kill history the plan handed it. %s';

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private Setup $setup,
        private Interruption $interruption = new Interruption(),
        private Ruling $ruling = new Ruling(),
        private BatchEnds $ends = new BatchEnds(),
        private ProcessLog $log = new NoProcessLog(),
    ) {
    }

    /** The shards a composed flow runs, each group of units `paths` entries match with a runner of its own. */
    public static function composed(Composed $composed): self
    {
        return new self(
            $composed->adapters,
            $composed->settings,
            $composed->setup,
            ruling: $composed->ruling,
            log: ErrorOutputLog::onErrorOutput(),
        );
    }

    /** The same, stopping before its next batch once this says so, as at its deadline. */
    public function interrupted(Interruption $interruption): self
    {
        return new self($this->adapters, $this->settings, $this->setup, $interruption, $this->ruling, $this->ends, $this->log);
    }

    /** The same, telling these what each batch judged as it ends (ADR-0120). */
    public function telling(BatchEnds $ends): self
    {
        return new self($this->adapters, $this->settings, $this->setup, $this->interruption, $this->ruling, $ends, $this->log);
    }

    /** The plan, where the checkout is the commit it was made on, as every shard and the verdict need. */
    public static function followed(Plan $plan, Repository $repository): Plan|CannotJudge
    {
        $head = $repository->head();

        return $head instanceof CannotTell
            ? CannotJudge::because(sprintf(self::NO_HEAD, $head->why()))
            : $plan->forCheckout($head);
    }

    /**
     * The shard `--shard` names, or, where it names none, the one the
     * environment's variables name (WhichShard); where the plan's shards pull
     * their work, the items that job takes, claimed through the artifact
     * service the gate was handed (ADR-0134).
     */
    public function run(
        Plan $plan,
        ShardId|Absent $named,
        Path $results,
        ArtifactService|CannotJudge|NotGiven $service = new NotGiven(),
    ): ShardLeft|ShardsLeft|CannotJudge {
        $followed = self::followed($plan, $this->adapters->repository);
        $id = $named instanceof ShardId ? $named : WhichShard::in($this->adapters->environment, $plan);

        if ($followed instanceof Plan && $id instanceof ShardId && $followed->pulls()) {
            $deadline = ShardDeadline::of($this->settings, $this->setup->clock->now());

            return $this->pulled($followed, $id, $results, $deadline, $service);
        }

        $stopped = WatchedJob::of($this->adapters->environment, $service, HttpClient::create())->stopped();

        $result = match (true) {
            $followed instanceof CannotJudge => $followed,
            $id instanceof CannotJudge => $id,
            $stopped !== '' => CannotJudge::because($stopped),
            default => $this->resultOf($followed, $id, ShardDeadline::of($this->settings, $this->setup->clock->now())),
        };

        return ShardLeft::after($result, $this->written($result, $results));
    }

    /** Every shard of a plan, one after another in this process, each leaving its result. */
    public function runAll(Plan $plan, Path $results): Written|CannotJudge
    {
        return $this->runAllBy($plan, $results, $this->deadline());
    }

    /**
     * Every shard of a plan, as {@see runAll()} runs them, stopping at a
     * deadline set before it, which several runs of one process share. Once
     * a shard stops because the run cannot pass, no later shard runs, and
     * the verdict reads each as stopped (ADR-0008, ADR-0103).
     */
    public function runAllBy(Plan $plan, Path $results, Deadline|Unlimited $deadline): Written|CannotJudge
    {
        foreach ($plan as $shard) {
            $result = $this->resultOf($plan, $shard->id(), $deadline);
            $ran = $this->written($result, $results);

            if ($ran instanceof CannotJudge) {
                return $ran;
            }

            if ($result instanceof ShardResult && $result->doomed()->stopped()) {
                break;
            }
        }

        return Written::to($results->value());
    }

    /** When this process must stop: its budget from now, or never where it has none (ADR-0008). */
    public function deadline(): Deadline|Unlimited
    {
        $budget = $this->settings->triage()->budget();

        return $budget instanceof Seconds ? Deadline::after($this->setup->clock->now(), $budget) : $budget;
    }

    /**
     * The items a job of a plan whose shards pull their work takes, each
     * run as its shard would be and left where the verdict reads it, until
     * none is left, one leaves the run no way to pass, or the job the run
     * watches failed (ADR-0135). Where the claims
     * cannot be reached, or fail, it runs its own items alone, and says so.
     */
    private function pulled(
        Plan $plan,
        ShardId $job,
        Path $results,
        Deadline|Unlimited $deadline,
        ArtifactService|CannotJudge|NotGiven $service,
    ): ShardsLeft|CannotJudge {
        $claims = ArtifactClaims::of(
            $service,
            $this->adapters->environment,
            HttpExchange::over(HttpClient::create()),
            $this->settings->shards()->pulling()->pull(),
            $plan->digest(),
            $job,
        );
        $queue = PullQueue::of($plan, $job);
        $left = ShardsLeft::none($job);
        $watched = WatchedJob::of($this->adapters->environment, $service, HttpClient::create());

        if ($claims instanceof CannotJudge) {
            [$queue, $left] = [$queue->ownAlone(), $left->alone($claims)];
        }

        while (($item = $queue->next()) instanceof Shard) {
            $stopped = $watched->stopped();

            if ($stopped !== '') {
                return $left->stopped($stopped);
            }

            $queue = $queue->without($item->id());
            $taken = $claims instanceof ArtifactClaims ? $claims->take($item->id()) : true;

            if ($taken instanceof CannotJudge) {
                [$claims, $queue, $left] = [$taken, $queue->ownAlone(), $left->alone($taken)];
                $taken = $item->worker()->number() === $job->number();
            }

            if (! $taken) {
                continue;
            }

            $result = $this->resultOf($plan, $item->id(), $deadline);
            $written = ShardLeft::after(
                $result,
                $result instanceof CannotJudge ? $result : $this->adapters->project->write(
                    Workspace::pulled($results, $item->id(), $job),
                    Contents::of(ShardResultFile::encode($result)),
                ),
            );

            if ($written instanceof CannotJudge) {
                return $written;
            }

            $left = $left->with($written);
            $finished = $claims instanceof ArtifactClaims ? $claims->finish($item->id()) : $item->id();

            if ($finished instanceof CannotJudge) {
                [$claims, $queue, $left] = [$finished, $queue->ownAlone(), $left->alone($finished)];
            }

            if ($result instanceof ShardResult && $result->doomed()->stopped()) {
                break;
            }
        }

        return $left;
    }

    /** A shard's result, left where the verdict reads it; or why it has none. */
    private function written(ShardResult|CannotJudge $result, Path $results): Written|CannotJudge
    {
        return $result instanceof CannotJudge ? $result : $this->adapters->project->write(
            Workspace::result($results, $result->shard()),
            Contents::of(ShardResultFile::encode($result)),
        );
    }

    /** What one shard of a plan came to, or why it has no result. */
    private function resultOf(Plan $plan, ShardId $id, Deadline|Unlimited $deadline): ShardResult|CannotJudge
    {
        $shard = $plan->shard($id);

        if ($shard instanceof CannotJudge) {
            return $shard;
        }

        $started = $this->setup->clock->now();
        $stopwatch = new Stopwatch($this->setup->clock, $started);
        $handoff = new Handoff(
            $this->adapters->project,
            Handoff::limits(),
            $this->adapters->maps,
            $this->settings->proofs()->coverageCompressionLevel(),
        );
        $map = $handoff->read($id);
        $history = $handoff->history($id);
        $ordering = Ordering::of(
            $this->settings->triage()->order(),
            $history instanceof KillHistory ? $history : KillHistory::none(),
        );
        $held = $handoff->subsumption($id);
        $search = KillSearch::of($ordering, $plan->briefing()->matrix());
        $search = $held instanceof Subsumption ? $search->holdingBack($held) : $search;
        $outcome = $map instanceof CoverageMap
            ? $this->mutated($plan, $shard, $map, $search, $deadline, $stopwatch)
            : $map;
        $ended = $this->setup->clock->now();
        $spent = Seconds::between($started, $ended);
        $identity = $this->adapters->runner->identity($this->adapters->withheld);
        $result = ShardResult::of(
            $plan->digest(),
            $id,
            $plan->keys()->of($shard->units()),
            $outcome instanceof CannotJudge ? $outcome : $outcome->result,
            Measurement::of($spent, $identity instanceof CannotJudge ? '' : $identity->runner(), Instant::at($ended))
                ->withSteps($stopwatch->steps())
                ->measuredBy(GateRelease::of($this->setup->gate)),
        );
        $result = $this->left($result, $outcome);

        return $result->withWarnings($result->warnings()->and($history instanceof CannotJudge ? Warnings::of(
            Warning::noting(sprintf(self::UNREAD_HISTORY, $id->number(), $history->why())),
        ) : Warnings::none())->and(Unsplit::warnings($this->settings, $id)));
    }

    /** The result with what the shard's mutating left beside its mutants, warnings too: none where it cannot judge. */
    private function left(ShardResult $result, Mutated|CannotJudge $outcome): ShardResult
    {
        return $outcome instanceof CannotJudge ? $result : $result
            ->withFlaky($outcome->flaky)
            ->withHeld($outcome->held)
            ->withUnjudged($outcome->unjudged)
            ->withChecks($outcome->checks)
            ->withWarnings($outcome->result->warnings())
            ->withDoomed($outcome->doomed);
    }

    /**
     * Every invocation's mutants, each limit laid on the start-up the shard
     * times once before its first mutant (see StartUpTiming), each timeout,
     * kill and mutant out of memory
     * judged by its unmutated control (see Invoking), with the survivors a second run killed, of each
     * unit but the held ones whose holding tests miss lines of them; or the
     * first cannot judge. A shard handed no map cannot judge at all. Under a
     * budget the units run in batches that fit the time left, and those the
     * time ran out before are unjudged; a shard's job limit starts every unit
     * and stops the runner (ADR-0114). A shard that stops once its run
     * cannot pass runs in chunks, and the units after the chunk that doomed
     * it are unjudged too, and static analysis checks each chunk's survivors
     * before its doom is judged. Each kill with no evidence is unjudged
     * (ADR-0014). Static analysis then checks the survivors no
     * chunk's check took up, in the time left, the analyser warmed up once
     * for the shard. Where `paths` entries match some of the shard's units,
     * each group of them runs on its own, as PartitionedRuns says (ADR-0074).
     */
    private function mutated(
        Plan $plan,
        Shard $shard,
        CoverageMap $map,
        KillSearch $search,
        Deadline|Unlimited $deadline,
        Stopwatch $stopwatch,
    ): Mutated|CannotJudge {
        $from = $stopwatch->now();
        $held = new HeldCoverage($this->adapters, $this->settings->reach()->heldCoverage())->checked($shard, $map);
        $stopwatch->handled(Step::HeldCoverage, $from, $shard->units()->held());

        if ($held instanceof CannotJudge) {
            return $held;
        }

        $kept = HeldCoverage::kept($shard, $held->misses());
        $groups = PathGroups::of($kept, $this->settings->effective()->setup()->paths());

        return $groups->isWhole()
            ? $this->mutatedKept($plan, $kept, $map, $held, $search, $deadline, $stopwatch)
            : new PartitionedRuns($this->adapters, $this->settings, $this->ruling, $this->setup->clock->now())->mutated(
                $groups,
                fn(Adapters $adapters, Settings $settings, Shard $part): Mutated|CannotJudge => new self(
                    $adapters,
                    $settings,
                    $this->setup,
                    $this->interruption,
                    $this->ruling,
                    $this->ends,
                    $this->log,
                )->mutatedKept($plan, $part, $map, $held, $search, $deadline, $stopwatch),
            );
    }

    /** The runs of a shard's units whose held coverage was checked, each with this run's adapters and settings. */
    private function mutatedKept(
        Plan $plan,
        Shard $kept,
        CoverageMap $map,
        HeldChecks $held,
        KillSearch $search,
        Deadline|Unlimited $deadline,
        Stopwatch $stopwatch,
    ): Mutated|CannotJudge {
        $samples = $this->settings->shards()->planning()->startUpSamples();
        $startUp = new StartUpTiming($this->adapters, $samples)->of($map, $kept->units());
        $pool = $this->adapters->pooled($this->settings->runner()->workers())
            ->pacedBy($this->settings->runner()->pace($this->settings->triage()->bounds()->unmeasuredStartUp()))
            ->startingIn($startUp)
            ->learning($plan->briefing()->learned()->multiple());
        $warmUp = new AnalyserWarmUp($this->settings->staticCheck()->dependents());
        $analyser = $this->adapters->analyser;
        $preChecking = $this->settings->staticCheck()->before() && $analyser instanceof AnalyserIdentity
            ? new PreChecking(
                $this->adapters,
                $this->setup->clock,
                $deadline,
                $this->settings->staticCheck()->seconds(),
                $this->known($plan, $analyser),
                $warmUp,
                $stopwatch,
                PreCheck::briefed($plan->briefing())->after($this->settings->staticCheck()->preCheckAfter()),
            )
            : new NoPreCheck();
        $checking = new SurvivorChecking(
            $this->adapters,
            $this->setup->clock,
            $deadline,
            $this->settings->staticCheck()->seconds(),
            $stopwatch,
            $warmUp,
            $this->settings->toggles()->on(Toggle::StaticCheckAfter),
        );
        $invoking = new Invoking(
            $this->adapters,
            $this->settings,
            $this->setup->clock,
            $deadline,
            $stopwatch,
            $map,
            $held->covered(),
            $checking,
            new PartedRuns($this->adapters, $this->settings, $map, $held->covered(), $this->log),
            $preChecking,
        );
        $doom = new Dooming($this->adapters, $this->settings, $this->setup)->of($plan);
        $stops = $this->settings->reach()->failingFast()->mode()->stops();
        $batched = new Batched(
            $invoking,
            $this->setup->clock,
            $deadline,
            ShardDeadline::fittedTo($this->settings, $deadline),
            $this->interruption,
            $doom,
            $stops,
            $this->ends,
        );
        $batching = Batching::opening($map->suiteDuration());
        $pruned = $plan->considered()->pruned();
        $requestFor = fn(Units $units): MutationRequest => $this->requestFor(
            $units,
            $kept,
            $search,
            $pool,
            $pruned,
        );
        $spent = match (true) {
            $doom instanceof Doom && $stops => $batched->spent(
                $this->weighed($kept, $plan),
                $batching->inChunksOf($this->settings->shards()->planning()->chunk()),
                $requestFor,
            ),
            $this->settings->triage()->budget() instanceof Seconds
                => $batched->spent($this->weighed($kept, $plan), $batching, $requestFor),
            default => new Unbatched($invoking, $this->setup->clock, $doom, $deadline)->spent($kept, $requestFor),
        };

        if ($spent instanceof CannotJudge) {
            return $spent;
        }

        $secrets = Secrets::withheldIn($this->adapters->environment, $this->adapters->withheld);
        $evidence = Hidden::in($spent->evidence, $spent->mutants, $secrets);
        $mutants = Unevidenced::judged($spent->mutants, $evidence);

        return new Mutated(
            MutationResult::of($mutants, $spent->skipped)
                ->withWarnings($spent->warnings)
                ->withEvidence($evidence),
            $spent->flaky,
            $held,
            $spent->unjudged,
            $spent->checks->plus(
                $preChecking instanceof PreChecking ? $preChecking->checks() : SurvivorChecks::none(),
            ),
            $spent->doomed,
        );
    }

    /**
     * What the ledgers the plan reads learned of the shard's analyser: its
     * own scope's first, then the default branch's (ADR-0020).
     */
    private function known(Plan $plan, AnalyserIdentity $identity): AnalyserHistory
    {
        $ledgers = Ledgers::read(
            $this->adapters->proofs,
            Standing::planned($plan),
            Writing::Never,
            $this->settings->proofs()->taken(),
        );

        return $ledgers->own()->learned()->analysers()->of($identity)
            ->and($ledgers->defaultBranch()->learned()->analysers()->of($identity));
    }

    /**
     * The shard's units in order, each weighed by what the cost model expects
     * of it, with what the ledgers the plan reads learned, at its share of the
     * shard's part of the test files; a shard measures no first run of its own.
     *
     * @return list<Weighed>
     */
    private function weighed(Shard $shard, Plan $plan): array
    {
        $ledgers = Ledgers::read(
            $this->adapters->proofs,
            Standing::planned($plan),
            Writing::Never,
            $this->settings->proofs()->taken(),
        );
        $weighed = [];

        foreach ($shard->units() as $unit) {
            $estimated = $ledgers->estimated($this->adapters->costs, $unit, FirstRun::unmeasured(), $this->setup->gate);
            $weighed[] = Weighed::of($unit, $shard->package(), $estimated)->sharedAmong($shard->testPart());
        }

        return $weighed;
    }

    /**
     * One invocation: a held unit alone by the tests that hold it, or files
     * by the whole suite, reading the maps the plan handed the shard, each
     * mutant's killers looked for as asked, leaving the mutators the plan
     * prunes out of the files it prunes them in, its mutants judged by the
     * shard's part of the test files (ADR-0117).
     */
    private function requestFor(
        Units $units,
        Shard $shard,
        KillSearch $search,
        Pool $pool,
        Pruned $pruned,
    ): MutationRequest {
        $files = Paths::none();
        $judgedBy = WholeSuite::tests();

        foreach ($units as $unit) {
            $files = $files->with($unit->path());
            $judgedBy = $unit->judgedBy();
        }

        $request = RunRequest::of($this->adapters, $this->settings, $files, $judgedBy);

        return $request->narrowedTo($files, $request->narrowing()->pruning($pruned)->inTestPart($shard->testPart()))
            ->across($pool)
            ->reusingCoverage(Handed::maps(Workspace::shardCoverage($shard->id()), Workspace::coverage()))
            ->searching($search);
    }
}
