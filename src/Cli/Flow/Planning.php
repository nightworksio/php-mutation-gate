<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\Coverage\Untested;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\RiskOrder;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\OwnOnly;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\TakenProofs;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

use function sprintf;

/**
 * `plan`: the units of the trees, what the change reaches, each considered
 * unit's key, and the shards the units no proof covers are cut into. A shard
 * of a package other than the project's root cannot be planned: the runner
 * runs the root package's suite, which does not judge another package's code.
 *
 * @internal
 */
final readonly class Planning
{
    private const string WAITS = 'The run cannot start without a fresh coverage map, and the plan was asked to plan'
        . ' early alone, so it plans nothing: the caller that hands a fresh map runs it (ADR-0135).';

    private const string NO_NEW_CODE = <<<'SAID'
        A pull request's or merge group's new code is judged against %s, and git cannot tell what changed since it,
        so no floor for new code can be held. %s
        Fetch it into the checkout before the plan.
        SAID;

    private const string PACKAGED = <<<'SAID'
        The package at %s has units to mutate, and the runner runs the suite of the project's root alone,
        which does not judge another package's code, so their mutants cannot be judged.
        SAID;

    private const string NOT_FULL = <<<'SAID'
        A full kill matrix needs Infection to keep running after a failure, which it cannot.
        SAID;

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /**
     * The plan of a run that records this much of the kill matrix, and how
     * its coverage map was measured, against the map its own scope or the
     * default branch keeps or, for `watch`, against the one its last round
     * left; or why there is no plan.
     */
    public function plan(
        Mode $mode,
        CoverageRun|CoverageRead $coverage,
        Cut $cut,
        MatrixKind $matrix,
        bool $ownMap = false,
        bool $earlyOnly = false,
    ): PlanMade|CannotJudge {
        $inventory = $this->adapters->runner->behaviour()->records($matrix)
            ? Inventory::forKeys($this->adapters, $this->settings)
            : CannotJudge::because(self::NOT_FULL);

        if ($inventory instanceof CannotJudge) {
            return $inventory;
        }

        $writing = Writing::from($this->settings->proofs()->write()->value);
        $ledgers = Ledgers::read(
            $this->adapters->proofs,
            $inventory->standing,
            $writing,
            $mode->takes($this->settings->proofs()->taken()),
        );
        $unchanged = new UnchangedPlan($this->adapters, $this->settings, $this->setup)
            ->of($inventory, $ledgers, $mode, $matrix);

        if ($unchanged instanceof PlanMade) {
            return $unchanged;
        }

        $early = $this->early($inventory, $ledgers, $mode, $coverage);

        if (! $early instanceof CoverageMeasured && $earlyOnly) {
            $waits = Plan::of($inventory->standing->head(), Digest::ofNothing(), Keys::none(), Shards::none());

            return PlanMade::of($waits->briefed(Briefing::standard()->waitingForAMap()), self::WAITS);
        }

        if (! $early instanceof CoverageMeasured) {
            return $this->measured($inventory, $ledgers, $mode, $coverage, $cut, $matrix, $ownMap);
        }

        $taking = $mode->takingNone();
        $none = Ledgers::read($this->adapters->proofs, $inventory->standing, $writing, TakenProofs::None);
        $made = $this->measured($inventory, $none, $taking, $early->request(), $cut, $matrix, $ownMap);

        return $made instanceof PlanMade ? $made->plannedEarly($early) : $made;
    }

    /**
     * The kept map a full split-stream run plans by without a fresh one,
     * where `splitStream.early` starts it early (ADR-0135): none handed, the
     * run full, its streams split by tests, and, under `no-match`, no proof
     * of the ledgers it reads may share its first five inputs; none where it
     * waits for a fresh map, or none is kept.
     */
    private function early(
        Inventory $inventory,
        Ledgers $ledgers,
        Mode $mode,
        CoverageRun|CoverageRead $coverage,
    ): CoverageMeasured|NotGiven {
        $pest = $this->settings->effective()->pest();

        $splits = $pest->splitsByTests($this->settings->runner()->choice());

        if (! $coverage instanceof CoverageRun || ! $mode->isFull() || ! $splits) {
            return NotGiven::value();
        }

        $reads = Keying::readsOf(
            $this->adapters,
            $this->settings,
            $this->setup,
            LearnedLimits::of($this->settings, $ledgers)->multiple(),
        );
        $match = ! $reads instanceof Digest
            || $ledgers->own()->proofs()->mayShareReads($reads)
            || $ledgers->defaultBranch()->proofs()->mayShareReads($reads);

        return $pest->splitStream()->early()->startsEarly($mode->isUnproven(), $match)
            ? new KeptCoverage($this->adapters, $this->settings, $this->setup)->keptAsIs($inventory)
            : NotGiven::value();
    }

    /**
     * The plan of a run whose coverage map is measured, or read, against the
     * map its own scope or the default branch keeps; or why there is none.
     */
    private function measured(
        Inventory $inventory,
        Ledgers $ledgers,
        Mode $mode,
        CoverageRun|CoverageRead $coverage,
        Cut $cut,
        MatrixKind $matrix,
        bool $ownMap,
    ): PlanMade|CannotJudge {
        $suites = SuiteCoverage::of($this->adapters, $inventory, $this->settings->proofs()->coverageCompressionLevel());
        $kept = new KeptCoverage($this->adapters, $this->settings, $this->setup);
        $names = new NameGraph($this->adapters)->named($inventory->suite);
        $entries = $kept->entries($inventory, $names);
        $measured = $kept->forRun($inventory, $entries, $coverage, $ownMap, $suites);
        $request = $measured->request();
        $map = $kept->mapOf($measured, $suites);

        $peak = $coverage instanceof CoverageRun ? $this->setup->memory->peak() : NotGiven::value();
        $cap = new CoverageCap($this->adapters, $this->settings);
        $map = $map instanceof CoverageMap ? $cap->holding($map, $peak) : $map;

        if ($map instanceof CannotJudge) {
            return $map;
        }

        $judges = MapJudges::of($this->adapters->runner, $map);
        $learned = LearnedLimits::of($this->settings, $ledgers);
        $keying = Keying::of(
            $this->adapters,
            $this->settings,
            $this->setup,
            $inventory->suite,
            $judges,
            $names,
            $learned->multiple(),
        );
        $at = Measuring::of($this->adapters, $request);
        $planned = $keying instanceof Keying
            ? $this->planned(
                $inventory,
                $ledgers,
                KeptCoverage::keysOf($entries, $map),
                $judges,
                $at,
                $keying,
                $mode,
                $learned->cut($cut),
                $matrix,
            )
            : $keying;

        $briefing = $measured->briefing(Briefing::standard()->weighing($peak)->recording($matrix));

        return $planned instanceof Plan
            ? PlanMade::of(
                $planned->briefed($learned->briefing(
                    $this->adapters->briefing($briefing->standingAs($planned->briefing())),
                )),
                $measured->said(),
            )->without($ledgers->unread())->learning(
                ...$learned->lines(),
                ...JudgedRun::of($this->adapters->project, OwnArchive::running())->lines($this->settings->reach()->everything()),
            )
            : $planned;
    }

    private function planned(
        Inventory $inventory,
        Ledgers $ledgers,
        EntryKeys|NotGiven $entryKeys,
        MapJudges $judges,
        MeasuredAt|Unplaced $at,
        Keying $keying,
        Mode $mode,
        Cut $cut,
        MatrixKind $matrix,
    ): Plan|CannotJudge {
        $map = $judges->map();
        $kind = $this->adapters->briefing(Briefing::standard()->recording($matrix))->profile();
        $base = $mode->changeBase(
            $ledgers,
            $inventory->standing,
            $kind,
            $this->settings->ci()->check(),
            $this->settings->reach()->defaultBranchSince(),
        );
        $reached = $base instanceof ChangeBase
            ? Reached::since(
                $base,
                $inventory->trees,
                $this->adapters,
                $this->settings,
                $inventory->suite,
                $judges,
                $this->setup->config,
            )
            : Reached::everything($inventory->trees, $base);
        $ownOnly = $reached->ownOnly();
        $now = $keying->digestsOf($inventory->units);
        $considering = $ledgers->considering(
            $inventory->units,
            $reached->reach(),
            $kind,
            OwnOnly::of($ownOnly, $now),
            $now,
        );
        $carried = $this->unitsOf($considering->carried());
        $carriedOwn = $carried->within($ownOnly);
        $keys = $keying->keysOf($considering->considered());
        $proving = $ledgers->proving($considering->considered(), $keys, $keying->base(), $kind);
        $changed = $this->newCode($inventory->standing, $reached);
        $auto = AutoSampling::of(
            $this->adapters,
            $this->settings,
            $this->setup,
            $mode,
            $inventory,
            $ledgers,
            $proving->toRun(),
            $changed,
            $ledgers->newest($kind),
        );
        $sample = new Sampled($this->adapters, $this->settings, $this->setup)
            ->drawn($auto->mode(), $inventory, $proving->toRun(), $reached, $changed, $ledgers->newest($kind), $base);
        $toRun = $sample instanceof Sample ? $sample->running($proving->toRun()) : $proving->toRun();
        $pruned = new PrunedUnits($this->adapters, $this->settings, $this->setup)
            ->of($base, $ledgers, $ledgers->newest($kind), $toRun, $keying);
        $opening = $map->suiteDuration();
        $firstRun = new FirstRuns(
            $this->adapters,
            $this->settings->shards()->planning()->startUpSamples(),
            $this->settings->shards()->costs()->firstRunShape(),
        )
            ->measured($map, $ledgers->untimed($toRun, $this->setup->gate));
        $shards = $this->shardsOf(
            $toRun,
            $inventory->trees,
            $ledgers,
            $firstRun,
            $cut->opening($opening),
            $this->riskOrder($reached, $inventory->trees, $ledgers->newest($kind), $toRun),
        );
        $shards = $shards instanceof Shards ? $this->opening($shards, $opening) : $shards;
        $digests = $this->committed($now, $inventory->standing->head());
        $reasons = KeysApart::said(
            OwnScopeStanding::said(
                $reached->reach()->reasons(),
                $proving->ownScopeProofs() + $considering->ownScopeProofs(),
            ),
            $proving->toRun(),
            $ledgers->defaultBranch(),
            $kind,
            $now,
            $ledgers->readsOwnScope() && $this->settings->effective()->reports()->suggest()->enabled(),
        );
        $standing = new NothingReached($this->adapters, $this->settings, $this->setup)->briefing(
            $shards,
            $inventory,
            $ledgers,
            $base,
            $kind,
            $proving->ownScopeProofs() + $considering->ownScopeProofs(),
            count($proving->proved()),
        );

        return match (true) {
            $shards instanceof CannotJudge => $shards,
            $changed instanceof CannotJudge => $changed,
            $sample instanceof CannotJudge => $sample,
            default => $this->handed(
                $entryKeys,
                Plan::of($inventory->standing->head(), $keying->base(), $keys, $shards)
                    ->on($inventory->standing->runOn())
                    ->briefed($standing)
                    ->digesting($digests)
                    ->considering(
                        Considered::everything()
                            ->reaching($changed, $auto->said($reasons))
                            ->untesting(Untested::of($changed, $map))
                            ->proving($this->unitsOf($proving->proved()))
                            ->carrying($carried)
                            ->carryingOwn($carriedOwn)
                            ->pruning($pruned)
                            ->sampling($sample),
                    )
                    ->naming($this->adapters->runner->names($map->tests(), $this->adapters->withheld)),
                $map,
                $at,
                $ledgers->killers(),
                new Inferring($this->adapters, $this->settings)->holding($ledgers),
            ),
        };
    }

    /**
     * The units to run cut into shards, each of the project's root package,
     * where the runner has no ignore marker in them the config refuses, and
     * each shard's units the riskiest first, the order a budget runs them in.
     */
    private function shardsOf(
        Units $toRun,
        Trees $trees,
        Ledgers $ledgers,
        FirstRun $firstRun,
        Cut $cut,
        RiskOrder $order,
    ): Shards|CannotJudge {
        $unmarked = new RunnerMarkers($this->adapters, $this->settings)->refusing($toRun);
        $shards = $unmarked instanceof Units
            ? $cut->cut($this->workload($unmarked, $trees, $ledgers, $firstRun), $trees)
            : $unmarked;

        return $shards instanceof Shards ? $this->rooted($shards, $order) : $shards;
    }

    /**
     * The run's digests, taken at the commit the checkout is at where the
     * working tree holds nothing that commit does not, as read once every key
     * is built: a change made while they were built and still on disk then
     * reads as a change. One undone before then leaves the files as the
     * commit holds them, and a digest taken of it meanwhile matches neither,
     * so the result that records it never counts. A commit made while the
     * plan was made moves HEAD, and the digests then stand for no commit.
     */
    private function committed(Digests $digests, Revision $head): Digests
    {
        $clean = $this->adapters->repository->isClean() === true;
        $still = $this->adapters->repository->head();

        return $clean && $still instanceof Revision && $still->name() === $head->name()
            ? $digests->takenAt($head)
            : $digests;
    }

    /**
     * The order a budget takes these units in, knowing when each of the least
     * risky last changed; by path among those where git cannot tell. A run
     * that knows no change, as a full one does not, reaches no unit by one.
     */
    private function riskOrder(Reached $reached, Trees $trees, NewestProofs $newest, Units $units): RiskOrder
    {
        $order = RiskOrder::of(
            $reached->changed() instanceof Changes ? $reached->reach() : Reach::nothing(Packages::of($trees)),
            $newest,
            MutantTriage::under($this->settings->triage()->timeouts(), $this->settings->triage()->headroom()),
        );
        $changed = $this->adapters->changes->lastChanged($order->least($units));

        return $changed instanceof ByPath ? $order->knowing($changed) : $order;
    }

    /** The shards, each expecting its runner to pay this opening run first. */
    private function opening(Shards $shards, Seconds $opening): Shards
    {
        $opened = [];

        foreach ($shards as $shard) {
            $opened[] = $shard->estimated($shard->estimate()->opening($opening));
        }

        return Shards::of(...$opened);
    }

    /**
     * The plan, once each shard is handed the whole map, the map of its own
     * files and the kill history of their functions. The whole map is where a
     * later local command, such as `pre-commit`, reads it without running the
     * suite.
     */
    private function handed(
        EntryKeys|NotGiven $keys,
        Plan $plan,
        CoverageMap $map,
        MeasuredAt|Unplaced $at,
        KillHistory $history,
        Subsumption|NotGiven $held,
    ): Plan|CannotJudge {
        $handed = new Handoff(
            $this->adapters->project,
            Handoff::limits(),
            $this->adapters->maps,
            $this->settings->proofs()->coverageCompressionLevel(),
        )
            ->write($plan, $map, $history, $at, $keys, $held);

        return $handed instanceof CannotJudge ? $handed : $plan;
    }

    /**
     * The lines the verdict holds to the floor for new code: those the change
     * added or modified. A pull request's or merge group's new code is judged
     * whatever the run considers, so where the run does not know its lines,
     * as a full run does not, they are those since its base: the branch a
     * pull request merges into, or the commit a merge group was built on.
     */
    private function newCode(Standing $standing, Reached $reached): Changes|CannotJudge
    {
        $changed = $reached->changed();
        $fetched = $standing->proposedBase();
        $changed = $changed instanceof CannotTell && $standing->runOn()->isProposed()
            ? Reached::linesSince($fetched, $this->adapters)
            : $changed;

        return match (true) {
            $changed instanceof Changes => $changed,
            $standing->runOn()->isProposed() => CannotJudge::because(
                sprintf(self::NO_NEW_CODE, $fetched->name(), $changed->why()),
            ),
            default => Changes::none(),
        };
    }


    /** The shards, each in this order, where each is of the project's root package. */
    private function rooted(Shards $shards, RiskOrder $order): Shards|CannotJudge
    {
        $ordered = Shards::none();

        foreach ($shards as $shard) {
            $package = $shard->package()->path();

            if (! $package->equals(Path::root())) {
                return CannotJudge::because(sprintf(self::PACKAGED, $package->value()));
            }

            $ordered = $ordered->with($shard->ordered($order));
        }

        return $ordered;
    }

    private function unitsOf(UnitResults $results): Units
    {
        $units = Units::none();

        foreach ($results as $result) {
            $units = $units->with($result->unit());
        }

        return $units;
    }

    /** Each unit to run, in the package its tree is in, weighed by what the cost model expects of it. */
    private function workload(Units $units, Trees $trees, Ledgers $ledgers, FirstRun $firstRun): Workload
    {
        $weighed = [];

        foreach ($units as $unit) {
            $tree = $trees->holding($unit->path());

            if ($tree instanceof Tree) {
                $estimated = $ledgers->estimated($this->adapters->costs, $unit, $firstRun, $this->setup->gate);
                $weighed[] = Weighed::of($unit, $tree->package(), $estimated);
            }
        }

        return Workload::of(...$weighed);
    }
}
