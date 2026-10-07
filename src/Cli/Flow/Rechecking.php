<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_keys;
use function array_slice;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\SurvivorsFirst;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Recheck\NoRecheck;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

/**
 * The last run's survivors run again first, on a branch of its own
 * (ADR-0020, decisions 19 to 21): of the newest proof in the run's own scope
 * of each unit the plan reaches, the mutants the comment lists as not killed,
 * judged as the verdict judges them, those on the change's lines first, at
 * most `survivorsFirst.max`. Each file's run makes the mutants of their
 * mutators alone, and each survivor is found again by the gate's id, which
 * holds no line, or is gone. The ledgers are only read: the result is a
 * signal, never a proof.
 */
final readonly class Rechecking
{
    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    public function recheck(Plan $plan): Rechecked|NoRecheck|CannotJudge
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
                Ledgers::read($this->adapters->proofs, $standing, Writing::Never)->own(),
                $survivorsFirst,
            ),
        };
    }

    private function inOwnScope(
        Plan $plan,
        Ledger $own,
        SurvivorsFirst $survivorsFirst,
    ): Rechecked|NoRecheck|CannotJudge {
        $trees = $this->adapters->trees->trees();

        if ($trees instanceof CannotJudge) {
            return $trees;
        }

        $units = $this->reached($plan);
        $mutants = $this->lastReported($own, $units);
        $reach = $this->reachOf($plan, $trees);
        $taken = $survivorsFirst->taken($this->survivorsAmong($mutants, $reach));

        return match (true) {
            $mutants === [] => NoRecheck::noEarlierRun(),
            $taken->count() === 0 => NoRecheck::noneLeft(),
            default => $this->rerun($taken, $units, $reach),
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
        $ignoring = Ignoring::of($this->settings->ignores()->entries(), $this->setup->clock->now());
        $triage = MutantTriage::under($this->settings->triage()->timeouts());
        $judged = [];

        foreach ($mutants as $mutant) {
            $judged[] = $ignoring->judged(JudgedMutant::of($mutant, $triage->judged($mutant)));
        }

        $equivalent = new StaticEquivalence($this->adapters, $this->settings)->proven($mutants)->proven;
        $survivors = JudgedMutants::of(...$judged)->provenEquivalent($equivalent)->within($reach);
        $survivors = $this->adapters->isSecurityOnly() ? $survivors->madeBy($this->adapters->security) : $survivors;

        return $survivors->survivors($this->uncovered());
    }

    /**
     * Each file's survivors run again in one run, of their mutators alone,
     * and each found again by its id.
     *
     * @param array<string, Unit> $units
     */
    private function rerun(Survivors $taken, array $units, Reach $reach): Rechecked|CannotJudge
    {
        $now = [];

        foreach ($this->byFile($taken) as $file => $survivors) {
            $made = $this->made(Path::of($file), $survivors, $units);

            if ($made instanceof CannotJudge) {
                return $made;
            }

            $now += $made;
        }

        $triage = MutantTriage::under($this->settings->triage()->timeouts());
        $rechecks = [];

        foreach ($taken as $before) {
            $key = $before->mutant()->id()->key();
            $rechecks[] = array_key_exists($key, $now)
                ? Recheck::found($before, JudgedMutant::of($now[$key], $triage->judged($now[$key]))->within($reach))
                : Recheck::gone($before);
        }

        return Rechecked::of($this->uncovered(), ...$rechecks);
    }

    /**
     * The mutants one run of this file makes of these survivors' mutators, by
     * the gate's id, judged by the tests that judge its unit. As a shard's
     * run does, it reads the coverage the plan handed on, the verdict's map
     * of every unit the plan considered as its own, rather than running the
     * suite under coverage again for each file, and runs a mutant on each
     * core the runner uses.
     *
     * @param  list<JudgedMutant>      $survivors
     * @param  array<string, Unit>     $units
     * @return array<string, Mutant>|CannotJudge
     */
    private function made(Path $file, array $survivors, array $units): array|CannotJudge
    {
        $mutators = [];

        foreach ($survivors as $survivor) {
            $mutators[$survivor->mutant()->mutator()] = true;
        }

        $names = array_keys($mutators);
        $judgedBy = $units[$file->value()]->judgedBy();
        $request = RunRequest::of($this->adapters, $this->settings, Paths::of($file), $judgedBy)
            ->across(Pool::of($this->adapters->processes(), $this->settings->runner()->workers()))
            ->reusingCoverage(Handed::maps(Workspace::verdictCoverage(), Workspace::coverage()));
        $result = $this->adapters->runner->mutate($request->narrowedTo(
            Paths::of($file),
            $request->narrowing()->toMutators(Mutators::named($names[0], ...array_slice($names, 1))),
        ));

        if ($result instanceof CannotJudge) {
            return $result;
        }

        $made = [];

        foreach ($result->mutants() as $mutant) {
            $made[$mutant->id()->key()] = $mutant;
        }

        return $made;
    }

    private function uncovered(): Uncovered
    {
        return Uncovered::from($this->settings->floors()->uncovered()->value);
    }

    /** @return array<string, list<JudgedMutant>> the survivors by the file they mutate, in the order taken */
    private function byFile(Survivors $taken): array
    {
        $byFile = [];

        foreach ($taken as $survivor) {
            $byFile[$survivor->mutant()->location()->file()->value()][] = $survivor;
        }

        return $byFile;
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
}
