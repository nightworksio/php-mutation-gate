<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\Coverage\Untested;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\RiskOrder;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
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
 */
final readonly class Planning
{
    private const string NO_NEW_CODE = <<<'SAID'
        A pull request's new code is judged against %s, and git cannot tell what changed since it,
        so no floor for new code can be held. %s
        Fetch the default branch into the checkout before the plan.
        SAID;

    private const string PACKAGED = <<<'SAID'
        The package at %s has units to mutate, and the runner runs the suite of the project's root alone,
        which does not judge another package's code, so their mutants cannot be judged.
        SAID;

    private const string OVER_CAP = <<<'SAID'
        The largest process of the suite's coverage run held %s resident, more than the %s each mutant's
        process may hold, so its mutants cannot be judged under that cap. Resident memory counts more than
        memory_limit does. %s
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
    ): PlanMade|CannotJudge {
        $inventory = $this->adapters->runner->behaviour()->records($matrix)
            ? Inventory::of($this->adapters, $this->settings)
            : CannotJudge::because(self::NOT_FULL);

        if ($inventory instanceof CannotJudge) {
            return $inventory;
        }

        $kept = new KeptCoverage($this->adapters, $this->settings, $this->setup);
        $entries = $kept->entries($inventory);
        $measured = $kept->forRun($inventory, $entries, $coverage, $ownMap);
        $request = $measured->request();
        $map = $this->adapters->runner->coverage(
            $request instanceof CoverageRun ? $this->adapters->covering($request) : $request,
        );

        $peak = $coverage instanceof CoverageRun ? $this->setup->memory->peak() : NotGiven::value();
        $map = $map instanceof CoverageMap ? $this->withinTheCap($map, $peak) : $map;

        if ($map instanceof CannotJudge) {
            return $map;
        }

        $keying = Keying::of($this->adapters, $this->settings, $this->setup, $inventory->suite, $map);
        $at = Measuring::of($this->adapters, $request);
        $planned = $keying instanceof Keying
            ? $this->planned($inventory, KeptCoverage::keysOf($entries, $map), $map, $at, $keying, $mode, $cut, $matrix)
            : $keying;

        $briefing = $measured->briefing(Briefing::standard()->weighing($peak)->recording($matrix));

        return $planned instanceof Plan
            ? PlanMade::of($planned->briefed($this->adapters->briefing($briefing)), $measured->said())
            : $planned;
    }

    /**
     * The map of the coverage run just run, where the suite, unmutated, held
     * no more than the cap in force in it: `runner.memory`, or the project's
     * own `memory_limit` where that lifts it; or why its mutants cannot be
     * judged under that cap (ADR-0004, decision 9). The peak is the most
     * resident memory of a process, an upper bound on what `memory_limit`
     * counts. A peak the system does not count, or a map another job wrote,
     * refuses nothing.
     */
    private function withinTheCap(CoverageMap $map, MemoryCap|NotGiven $peak): CoverageMap|CannotJudge
    {
        $cap = ProjectMemoryLimit::inForce(
            $this->adapters->project,
            PhpUnitConfig::among($this->adapters->runner->definitions()),
            $this->settings->runner()->memory(),
        );

        return $peak instanceof MemoryCap && $cap->isExceededBy($peak)
            ? CannotJudge::because(sprintf(self::OVER_CAP, $peak->written(), $cap->written(), Exhaustion::ADVICE))
            : $map;
    }

    private function planned(
        Inventory $inventory,
        EntryKeys|NotGiven $entryKeys,
        CoverageMap $map,
        MeasuredAt|Unplaced $at,
        Keying $keying,
        Mode $mode,
        Cut $cut,
        MatrixKind $matrix,
    ): Plan|CannotJudge {
        $writing = Writing::from($this->settings->proofs()->write()->value);
        $ledgers = Ledgers::read($this->adapters->proofs, $inventory->standing, $writing);
        $base = $mode->base($ledgers);
        $reached = $base instanceof Revision
            ? Reached::since($base, $inventory->trees, $this->adapters, $this->settings, $inventory->suite, $map)
            : Reached::everything($inventory->trees, $base);
        $considering = $ledgers->considering($inventory->units, $reached->reach(), $matrix);
        $keys = $keying->keysOf($considering->considered());
        $proving = $ledgers->proving($considering->considered(), $keys, $keying->base(), $matrix);
        $opening = $map->suiteDuration();
        $firstRun = new FirstRuns($this->adapters, StartUpSamples::standard())
            ->measured($map, $ledgers->untimed($proving->toRun()));
        $shards = $this->shardsOf(
            $proving->toRun(),
            $inventory->trees,
            $ledgers,
            $firstRun,
            $cut->opening($opening),
            $this->riskOrder($reached, $inventory->trees, $ledgers, $proving->toRun()),
        );
        $shards = $shards instanceof Shards ? $this->opening($shards, $opening) : $shards;
        $changed = $this->newCode($inventory->standing, $reached);
        $digests = $this->committed($keying->digestsOf($proving->toRun()), $inventory->standing->head());

        return match (true) {
            $shards instanceof CannotJudge => $shards,
            $changed instanceof CannotJudge => $changed,
            default => $this->handed(
                $entryKeys,
                Plan::of($inventory->standing->head(), $keying->base(), $keys, $shards)
                    ->on($inventory->standing->runOn())
                    ->digesting($digests)
                    ->considering(
                        Considered::everything()
                            ->reaching($changed, $reached->reach()->reasons())
                            ->untesting(Untested::of($changed, $map))
                            ->proving($this->unitsOf($proving->proved()))
                            ->carrying($this->unitsOf($considering->carried())),
                    )
                    ->naming($this->adapters->runner->names($map->tests(), $this->adapters->withheld)),
                $map,
                $at,
                $ledgers->killers(),
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
    private function riskOrder(Reached $reached, Trees $trees, Ledgers $ledgers, Units $units): RiskOrder
    {
        $order = RiskOrder::of(
            $reached->changed() instanceof Changes ? $reached->reach() : Reach::nothing(Packages::of($trees)),
            $ledgers->newest(),
            MutantTriage::under($this->settings->triage()->timeouts()),
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
    ): Plan|CannotJudge {
        $handed = new Handoff($this->adapters->project, Handoff::limits())->write($plan, $map, $history, $at, $keys);

        return $handed instanceof CannotJudge ? $handed : $plan;
    }

    /**
     * The lines the verdict holds to the floor for new code: those the change
     * added or modified. A pull request's new code is judged whatever the run
     * considers, so where the run does not know its lines, as a full run
     * does not, they are those since the default branch.
     */
    private function newCode(Standing $standing, Reached $reached): Changes|CannotJudge
    {
        $changed = $reached->changed();
        $fetched = $standing->fetchedDefaultBranch();
        $changed = $changed instanceof CannotTell && $standing->runOn()->isPullRequest()
            ? Reached::linesSince($fetched, $this->adapters)
            : $changed;

        return match (true) {
            $changed instanceof Changes => $changed,
            $standing->runOn()->isPullRequest() => CannotJudge::because(
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
                $estimated = $ledgers->estimated($this->adapters->costs, $unit, $firstRun);
                $weighed[] = Weighed::of($unit, $tree->package(), $estimated);
            }
        }

        return Workload::of(...$weighed);
    }
}
