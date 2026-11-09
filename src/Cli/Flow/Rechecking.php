<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Recheck\NoRecheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Recheck\RecheckJobs;
use NightWorksIO\MutationGate\Core\Recheck\RecheckShare;
use NightWorksIO\MutationGate\Core\Recheck\ShareOf;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

/**
 * The last run's survivors run again first, on a branch of its own
 * (ADR-0020, ADR-0106): of the newest proof in the run's own scope of each
 * unit the plan reaches, the mutants the comment lists as not killed,
 * judged as the verdict judges them, those on the change's lines first, at
 * most `survivorsFirst.max`. One job runs them all, or each of
 * `survivorsFirst.shards` jobs runs its share of their files and one more
 * pairs what the shares made with the survivors taken again. The ledgers are
 * only read: the result is a signal, never a proof.
 *
 * @internal
 */
final readonly class Rechecking
{
    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** Every survivor taken, run again in this one job. */
    public function recheck(Plan $plan): Rechecked|NoRecheck|CannotJudge
    {
        $taken = $this->taken($plan);

        if (! $taken instanceof TakenSurvivors) {
            return $taken;
        }

        $ran = $this->runs()->ran($taken, ShareOf::whole());

        return $ran instanceof CannotJudge ? $ran : $this->runs()->rechecked($taken, $ran);
    }

    /** The jobs a CI shares the re-check among; one, to say why, where the survivors taken cannot be told. */
    public function jobs(Plan $plan): RecheckJobs|CannotJudge
    {
        $taken = $this->taken($plan);
        $first = $this->settings->triage()->survivorsFirst();

        return match (true) {
            $taken instanceof TakenSurvivors => RecheckJobs::for($taken->files(), $first),
            $taken instanceof NoRecheck => RecheckJobs::none($first),
            default => $taken,
        };
    }

    /** What one job's share of the files ran again. */
    public function share(Plan $plan, ShareOf $share): RecheckShare|NoRecheck|CannotJudge
    {
        $taken = $this->taken($plan);

        return $taken instanceof TakenSurvivors ? $this->runs()->ran($taken, $share) : $taken;
    }

    /** The survivors taken again, each paired with what the shares made of it, or not reached where none ran it. */
    public function combined(Plan $plan, RecheckShare $shares): Rechecked|NoRecheck|CannotJudge
    {
        $taken = $this->taken($plan);

        return $taken instanceof TakenSurvivors ? $this->runs()->rechecked($taken, $shares) : $taken;
    }

    private function taken(Plan $plan): TakenSurvivors|NoRecheck|CannotJudge
    {
        $survivorsFirst = $this->settings->triage()->survivorsFirst();
        $standing = Standing::planned($plan);
        $scope = $standing->runOn()->scope();

        return match (true) {
            $survivorsFirst->isOff() => NoRecheck::off(),
            ! $scope instanceof Scope => NoRecheck::withoutAScope(),
            $scope->equals($standing->defaultBranch()) => NoRecheck::onTheDefaultBranch(),
            default => $this->inOwnScope(
                $plan,
                Ledgers::read(
                    $this->adapters->proofs,
                    $standing,
                    Writing::Never,
                    $this->settings->proofs()->taken(),
                )->own(),
            ),
        };
    }

    private function inOwnScope(Plan $plan, Ledger $own): TakenSurvivors|NoRecheck|CannotJudge
    {
        $trees = $this->adapters->trees->trees();

        if ($trees instanceof CannotJudge) {
            return $trees;
        }

        $units = $this->reached($plan);
        $mutants = $this->lastReported($own, $units);
        $reach = $this->reachOf($plan, $trees);
        $taken = $this->settings->triage()->survivorsFirst()->taken($this->survivorsAmong($mutants, $reach));

        return match (true) {
            $mutants === [] => NoRecheck::noEarlierRun(),
            $taken->count() === 0 => NoRecheck::noneLeft(),
            default => TakenSurvivors::of($taken, $units, $reach),
        };
    }

    /**
     * The mutants the comment would list as not killed among these, as the
     * verdict judges them.
     *
     * @param list<Mutant> $mutants
     */
    private function survivorsAmong(array $mutants, Reach $reach): Survivors
    {
        $ignoring = Ignoring::of(
            $this->settings->ignores()->entries(),
            $this->setup->clock->now(),
            $this->settings->ignores()->notice(),
        );
        $triage = MutantTriage::under($this->settings->triage()->timeouts(), $this->settings->triage()->headroom());
        $judged = [];

        foreach ($mutants as $mutant) {
            $judged[] = $ignoring->judged(JudgedMutant::of($mutant, $triage->judged($mutant)));
        }

        $equivalent = new StaticEquivalence($this->adapters, $this->settings)->proven($mutants)->proven;
        $survivors = JudgedMutants::of(...$judged)->provenEquivalent($equivalent)->within($reach);
        $survivors = $this->adapters->isSecurityOnly() ? $survivors->madeBy($this->adapters->security) : $survivors;

        return $survivors->survivors($this->settings->floors()->uncovered());
    }

    /** @return array<string, Unit> every unit the plan reaches, to run or proved, by its path; none it only carries */
    private function reached(Plan $plan): array
    {
        $units = [];

        foreach ($plan as $shard) {
            foreach ($shard->units() as $unit) {
                $units[$unit->path()->value()] = $unit;
            }
        }

        foreach ($plan->considered()->proved() as $unit) {
            $units[$unit->path()->value()] = $unit;
        }

        return $units;
    }

    /**
     * Every mutant the newest proof in this ledger of each of these units reported in full.
     *
     * @param  array<string, Unit> $units
     * @return list<Mutant>
     */
    private function lastReported(Ledger $own, array $units): array
    {
        $newest = $own->proofs()->newest();
        $mutants = [];

        foreach ($units as $unit) {
            $proof = $newest->of($unit->path());
            $mutants = $proof instanceof Proof ? [...$mutants, ...$proof->reported()] : $mutants;
        }

        return $mutants;
    }

    /** What the plan's change reaches, line by line, so survivors on its lines go first. */
    private function reachOf(Plan $plan, Trees $trees): Reach
    {
        $reach = Reach::nothing(Packages::of($trees));

        foreach ($plan->considered()->changed() as $change) {
            $reach = $reach->withLines($change->path(), $change->lines());
        }

        return $reach;
    }

    private function runs(): RecheckRuns
    {
        return new RecheckRuns($this->adapters, $this->settings, $this->setup);
    }
}
