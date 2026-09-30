<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;
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

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    public function plan(Mode $mode, CoverageRun|CoverageRead $coverage, Cut $cut): Plan|CannotJudge
    {
        $inventory = Inventory::of($this->adapters, $this->settings);

        if ($inventory instanceof CannotJudge) {
            return $inventory;
        }

        $map = $this->adapters->runner->coverage(
            $coverage instanceof CoverageRun ? $coverage->withholding($this->adapters->withheld) : $coverage,
        );

        if ($map instanceof CannotJudge) {
            return $map;
        }

        $keying = Keying::of($this->adapters, $this->settings, $this->setup, $inventory->suite, $map);

        return $keying instanceof Keying ? $this->planned($inventory, $map, $keying, $mode, $cut) : $keying;
    }

    private function planned(
        Inventory $inventory,
        CoverageMap $map,
        Keying $keying,
        Mode $mode,
        Cut $cut,
    ): Plan|CannotJudge {
        $writing = Writing::from($this->settings->proofs()->write()->value);
        $ledgers = Ledgers::read($this->adapters->proofs, $inventory->standing, $writing);
        $base = $mode->base($ledgers);
        $reached = $base instanceof Revision
            ? Reached::since($base, $inventory->trees, $this->adapters, $this->settings, $inventory->suite, $map)
            : Reached::everything($inventory->trees, $base);
        $considering = Considering::of(
            $inventory->units,
            $reached->reach(),
            $ledgers->defaultBranch()->proofs(),
            $ledgers->own()->proofs(),
        );
        $keys = $keying->keysOf($considering->considered());
        $proving = $ledgers->proving($considering->considered(), $keys, $keying->base());
        $shards = $this->shardsOf(
            $proving->toRun(),
            $inventory->trees,
            $ledgers,
            $cut->opening($this->openingOf($map)),
        );
        $changed = $this->newCode($inventory->standing, $reached);

        return match (true) {
            $shards instanceof CannotJudge => $shards,
            $changed instanceof CannotJudge => $changed,
            default => $this->handed(
                Plan::of($inventory->standing->head(), $keying->base(), $keys, $shards)
                    ->on($inventory->standing->runOn())
                    ->considering(
                        Considered::everything()
                            ->reaching($changed, $reached->reach()->reasons())
                            ->proving($this->unitsOf($proving->proved()))
                            ->carrying($this->unitsOf($considering->carried())),
                    )
                    ->naming($this->adapters->runner->names($map->tests(), $this->adapters->withheld)),
                $map,
                $ledgers->killers(),
            ),
        };
    }

    /**
     * The units to run cut into shards, each of the project's root package,
     * where the runner has no ignore marker in them the config refuses.
     */
    private function shardsOf(Units $toRun, Trees $trees, Ledgers $ledgers, Cut $cut): Shards|CannotJudge
    {
        $unmarked = new RunnerMarkers($this->adapters, $this->settings)->refusing($toRun);
        $shards = $unmarked instanceof Units
            ? $cut->cut($this->workload($unmarked, $trees, $ledgers), $trees)
            : $unmarked;

        return $shards instanceof Shards ? $this->rooted($shards) : $shards;
    }

    /**
     * How long a shard's runner spends on its opening run, before it has
     * measured one of its own: the coverage run's, every test's duration.
     */
    private function openingOf(CoverageMap $map): Seconds
    {
        $seconds = 0.0;

        foreach ($map->tests() as $test) {
            $duration = $map->durationOf($test);
            $seconds += $duration instanceof Seconds ? $duration->seconds() : 0.0;
        }

        return Seconds::of($seconds);
    }

    /**
     * The plan, once each shard is handed the map of its own files and the
     * kill history of their functions, and, outside CI, once the whole map is
     * left where a later local command, such as `pre-commit`, reads it without
     * running the suite.
     */
    private function handed(Plan $plan, CoverageMap $map, KillHistory $history): Plan|CannotJudge
    {
        $handed = new Handoff($this->adapters->project)->write($plan, $map, $history);
        $left = $handed instanceof CannotJudge || $this->adapters->environment->inCi()
            ? $handed
            : $this->adapters->project->write(
                CoverageMapFile::in(Workspace::coverage()),
                Contents::of(CoverageMapFile::encode($map)),
            );

        return $left instanceof CannotJudge ? $left : $plan;
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

    /** The shards, where each is of the project's root package. */
    private function rooted(Shards $shards): Shards|CannotJudge
    {
        foreach ($shards as $shard) {
            $package = $shard->package()->path();

            if (! $package->equals(Path::root())) {
                return CannotJudge::because(sprintf(self::PACKAGED, $package->value()));
            }
        }

        return $shards;
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
    private function workload(Units $units, Trees $trees, Ledgers $ledgers): Workload
    {
        $weighed = [];

        foreach ($units as $unit) {
            $tree = $trees->holding($unit->path());

            if ($tree instanceof Tree) {
                $weighed[] = Weighed::of(
                    $unit,
                    $tree->package(),
                    $this->adapters->costs->cost($unit, $ledgers->timings()),
                );
            }
        }

        return Workload::of(...$weighed);
    }
}
