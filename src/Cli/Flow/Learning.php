<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\MutantTimes;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Untimed;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Order\Lesson;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\KillPace;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Pruning\Outcomes;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * What the ledger a verdict leaves learns of the run: in a run neither
 * narrowed nor sampled down to a part of its mutators or tests, what each
 * mutant came to and which subsumes which, what each shard taught the cost
 * model and each mutator's mutants cost (ADR-0025, ADR-0076, ADR-0079);
 * and in every run the first killer of each mutant killed, and how many
 * shards ran at once (ADR-0013, ADR-0073).
 *
 * @internal
 */
final readonly class Learning
{
    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** The ledger, with every proof of the run, having learned what the run teaches; or why it cannot learn it. */
    public function learned(
        Ledger $proved,
        UnitResults $fresh,
        Results $results,
        JudgedMutants $judged,
        Ledgers $ledgers,
        Plan $plan,
        RunTimings|Untimed $timings,
    ): Ledger|CannotJudge {
        $learned = $this->adapters->narrowing->isNone()
            ? $this->timed(
                $this->subsumed($this->survived($proved, $fresh), $fresh, $plan),
                $results,
                $judged,
                $ledgers->timings(),
                $plan,
            )
            : $proved;
        $ledger = $this->taught($learned, $fresh);

        return $ledger instanceof Ledger ? $this->paced($ledger, $timings) : $ledger;
    }

    /**
     * The ledger, having learned what each mutant the run judged itself came
     * to, by its runner and mutator, which decides what later runs prune
     * (ADR-0025).
     */
    private function survived(Ledger $ledger, UnitResults $fresh): Ledger
    {
        $window = $this->settings->reach()->pruning()->window();
        $runner = $this->settings->runner()->name();

        return $ledger->withLearned($ledger->learned()->survival()->after($runner, $window, ...Outcomes::of($fresh)));
    }

    /**
     * The ledger, having learned which mutant subsumes which in each file
     * the run judged every mutant of with every test that killed it, in
     * place of what it held of those files (ADR-0079).
     */
    private function subsumed(Ledger $ledger, UnitResults $fresh, Plan $plan): Ledger
    {
        return $plan->briefing()->profile()->matrix() === MatrixKind::Full
            ? $ledger->withLearned($ledger->learned()->subsumption()->afterRun($fresh))
            : $ledger;
    }

    /**
     * The ledger, having learned the first killer of every mutant the shards
     * killed, in the function it is in, and keeping the killers of functions
     * only in the files that still exist (ADR-0013).
     */
    private function taught(Ledger|CannotJudge $ledger, UnitResults $fresh): Ledger|CannotJudge
    {
        if ($ledger instanceof CannotJudge) {
            return $ledger;
        }

        $files = Paths::none();

        foreach ($ledger->learned()->killers()->functions() as $ranked) {
            $files = $files->with($ranked->function()->file());
        }

        foreach ($fresh as $result) {
            foreach ($result->mutants() as $mutant) {
                $files = $files->with($mutant->location()->file());
            }
        }

        $functions = SourceFunctions::read($this->adapters->project, $files);

        return $functions instanceof SourceFunctions ? $this->killedIn($ledger, $fresh, $functions) : $functions;
    }

    private function killedIn(Ledger $ledger, UnitResults $fresh, SourceFunctions $functions): Ledger
    {
        $lessons = [];

        foreach ($fresh as $result) {
            foreach ($result->mutants() as $mutant) {
                $lessons[] = Lesson::of($mutant, $functions->around($mutant));
            }
        }

        return $ledger
            ->withLearned($ledger->learned()->killers()->learnedFrom(...$lessons))
            ->keepingKillersIn($functions->files());
    }

    /**
     * The ledger, with what each shard taught the cost model of the units it
     * ran, timed by the map it was handed, the shards that each judge a unit
     * by a part of the test files adding up (ADR-0117), smoothed over the
     * timing every ledger read held of it: a unit its budget ran out before
     * took none of its time, and a mutant a shard's part did not reach none of
     * its time; each mutant a test killed, its time over its covering tests'
     * own time by that map, which a learned `timeouts.multiple` is read from
     * (ADR-0073); and what each mutator's mutants the shard ran cost and came
     * to, as the verdict judged them (ADR-0076).
     */
    private function timed(
        Ledger $ledger,
        Results $results,
        JudgedMutants $judged,
        Timings $held,
        Plan $plan,
    ): Ledger|CannotJudge {
        $pruned = $plan->considered()->pruned()->files();
        $handoff = new Handoff(
            $this->adapters->project,
            Handoff::limits(),
            $this->adapters->maps,
            $this->settings->proofs()->coverageCompressionLevel(),
        );
        $times = MutantTimes::none();
        $timings = Timings::none();

        foreach ($results->shards() as [$shard, $result, $mutated]) {
            $map = $handoff->read($shard->id());

            if ($map instanceof CannotJudge) {
                return $map;
            }

            $reached = $mutated->mutants()->reached();
            $ran = $shard->units()->except($result->unjudged());
            $times = $times->and(MutantTimes::of($reached, $map, $result->measured()->spent()));
            $timings = $timings->plus($this->adapters->costs->learn(
                $ran->except($ran->within($pruned)),
                $reached,
                $map,
                $result->measured(),
            ));
            $paces = KillPace::ofAll($reached, $map);
            $ledger = $ledger->withLearned($ledger->learned()->limits()->afterKills(...$paces));
        }

        $runner = $this->settings->runner()->name();

        return $ledger
            ->withTimings($timings->smoothedOver($held, $this->settings->shards()->planning()->newest()))
            ->withLearned($ledger->learned()->tallies()->after($runner, $judged, $times));
    }

    /** The ledger, having learned, for a run cut into shards, how many of them ran at once (ADR-0073). */
    private function paced(Ledger $ledger, RunTimings|Untimed $timings): Ledger
    {
        return $timings instanceof RunTimings && $timings->isSharded()
            ? $ledger->withLearned($ledger->learned()->limits()->afterRuns($timings->mostAtOnce()))
            : $ledger;
    }
}
