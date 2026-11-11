<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\TakenProofs;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Port\ProofStore;
use RuntimeException;

/** A two-shard plan's runs as the verdict records them, with the map, run and ledgers they read. */
final readonly class RecordedRuns
{
    /** The last run a verdict hands the ledger it writes. */
    public static function lastRun(): LastRun
    {
        return LastRun::of(JudgedCommits::of('5eeca8f0a1b2c3d4e5f60718293a4b5c6d7e8f90'), 'mutation-gate', RunProfile::standard());
    }

    /** Every mutant the shards ran, judged as its runner reported it. */
    public static function judged(Results $results): JudgedMutants
    {
        $judged = JudgedMutants::none();

        foreach ($results->units() as $result) {
            foreach ($result->mutants() as $mutant) {
                $judged = $judged->with(JudgedMutant::of($mutant, MutantJudgement::reported($mutant->status())));
            }
        }

        return $judged;
    }

    /** Every shard of a plan run in a project, each handed the map, and the results read back. */
    public static function ranOf(Plan $plan, string $project, ScriptedRunner $runner, CoverageMap $map): Results
    {
        return self::ranWith($plan, $project, $runner, $map, Flows::settings(), Flows::setup());
    }

    /**
     * Every shard of a plan run in a project on these settings and this setup,
     * each handed the map, with these ports besides, and the results read back.
     */
    public static function ranWith(
        Plan $plan,
        string $project,
        ScriptedRunner $runner,
        CoverageMap $map,
        Settings $settings,
        Setup $setup,
        object ...$ports,
    ): Results {
        new Handoff(Directory::at($project), HandedMaps::limits())->write($plan, $map, KillHistory::none(), Unplaced::map());
        new Running(Flows::adapters($project, [], $runner, ...$ports), $settings, $setup)->runAll($plan, Workspace::results());
        $results = Results::read($plan, Workspace::results(), Directory::at($project));

        return $results instanceof Results ? $results : throw new RuntimeException($results->why());
    }

    /** Every shard of a plan run in a project whose held unit's tests miss lines of it, and the results read back. */
    public static function ranMissing(Plan $plan, string $project, CoverageMap $map): Results
    {
        new Handoff(Directory::at($project), HandedMaps::limits())->write($plan, $map, KillHistory::none(), Unplaced::map());
        $adapters = Flows::adapters($project, [], new CoverageAsked(ScriptedRunner::fixture(), CoverageMap::of()));
        new Running($adapters, Flows::settings(), Flows::setup())->runAll($plan, Workspace::results());
        $results = Results::read($plan, Workspace::results(), Directory::at($project));

        return $results instanceof Results ? $results : throw new RuntimeException($results->why());
    }

    /**
     * Every shard of the two-shard plan run in a project, each handed the map, and
     * the results read back.
     */
    public static function ran(string $project, ScriptedRunner $runner, CoverageMap $map): Results
    {
        return self::ranOf(Planned::twoShards(), $project, $runner, $map);
    }

    /** The map the plan hands every shard. */
    public static function map(): CoverageMap
    {
        return CoverageMap::of()
            ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
            ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.5));
    }

    /** The run that established the proofs, at the plan's base. */
    public static function run(Plan $plan): Run
    {
        return Run::of('local:now', Moment::at('2026-09-30T12:00:00Z'), $plan->base());
    }

    /** The ledgers a run reads from this store for this plan, taking every proof. */
    public static function ledgers(ProofStore $store, Plan $plan, Writing $writing = Writing::Auto): Ledgers
    {
        return Ledgers::read(
            $store,
            Standing::planned($plan),
            $writing,
            TakenProofs::Every,
        );
    }
}
