<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;
use function implode;
use function is_array;

use NightWorksIO\MutationGate\Core\Assertion\TestFiles;
use NightWorksIO\MutationGate\Core\Assertion\Weakness;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Lowering;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Improvement;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Proving;
use NightWorksIO\MutationGate\Core\Proof\Agreement;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Removal\Removals;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Carrying;
use NightWorksIO\MutationGate\Core\Verdict\ChangesSince;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\LeftUnjudged;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Ratchet;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Unfinished;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

/**
 * `verdict --plan --results`: every shard's result merged with the proved and
 * carried ones, every tree judged whole against its floor, and in a pull
 * request the new code against its own floor, a raise that must be committed
 * and a floor lowered without its reason; the survivors that share a cause
 * clustered, and those a weak test let through marked. Then the reports,
 * and the ledger.
 * A tree held to no floor stops a run in CI, and is warned of elsewhere.
 */
final readonly class Judging
{
    private const string NO_MATRIX = 'The kill matrix holds each mutant\'s killers alone. %s';

    private const string FAILED = 'The verdict failed, so the commit did not pass.';

    /** Why a passing verdict's commit is not recorded as passed: a run from it must still reach what it left. */
    private const string UNJUDGED = 'The run left mutants unjudged, so its commit is not recorded as passed.';

    private const string UNNAMED = 'The reports name each test by its coverage id. %s';

    private const string UNFLOORED = '%s has no floor yet. Run mutation-gate baseline --write and commit %s.';

    private const string MEASURED = "The baseline this run measured, ready to commit as %s:\n%s";

    private const string VANISHED = <<<'SAID'
        The ledger no longer holds a proof the plan took, so these units cannot be judged:
        %s
        A proof pruned, or a cache replaced, between the plan and the verdict does this. Plan the run again.
        SAID;

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private Setup $setup,
        private Reporting $reporting,
    ) {
    }

    public function verdict(Plan $plan, Results $results): Judged|Invalid|CannotJudge
    {
        $reporters = $this->reporting->reporters($this->settings, $plan->runOn());

        if (! is_array($reporters)) {
            return $reporters;
        }

        $assessed = $this->grounded($plan, $results, Writing::from($this->settings->proofs()->write()->value));

        return $assessed instanceof Assessed ? $this->judged($plan, $results, $assessed, $reporters) : $assessed;
    }

    /**
     * A plan's results judged again as the verdict judged them, reporting
     * nothing and writing no ledger, for a command that explains what a run
     * found (ADR-0014, decision 12).
     */
    public function again(Plan $plan, Results $results): Verdict|CannotJudge
    {
        $assessed = $this->grounded($plan, $results, Writing::Never);

        return $assessed instanceof Assessed ? $assessed->verdict : $assessed;
    }

    /** @param list<Reporter> $reporters */
    private function judged(Plan $plan, Results $results, Assessed $assessed, array $reporters): Judged|CannotJudge
    {
        $verdict = $assessed->verdict;
        $recorded = $this->recorded($plan, $results, $assessed->ledgers, $verdict, $assessed->ownScopeProofs);

        if ($recorded instanceof CannotJudge) {
            return $recorded;
        }

        $judged = new Judged($verdict, [$recorded, ...$this->reported($verdict, $reporters)], $assessed->baseline);

        return $assessed->refused ? $judged->refusing() : $judged;
    }

    /** A plan's results judged over the trees and the committed baseline, or why either cannot be read. */
    private function grounded(Plan $plan, Results $results, Writing $writing): Assessed|CannotJudge
    {
        $trees = $this->adapters->trees->trees();
        $baseline = new Baselines($this->adapters, $this->settings->floors()->baseline())->committed();

        return match (true) {
            $trees instanceof CannotJudge => $trees,
            $baseline instanceof CannotJudge => $baseline,
            default => $this->assessed($plan, $results, $trees, $baseline, $writing),
        };
    }

    /**
     * Every shard's result merged with the proved and carried ones and
     * judged, from the ledgers read as the run may write them.
     */
    private function assessed(
        Plan $plan,
        Results $results,
        Trees $trees,
        Baseline $baseline,
        Writing $writing,
    ): Assessed|CannotJudge {
        $ledgers = Ledgers::read($this->adapters->proofs, Standing::planned($plan), $writing);
        $proving = $ledgers->proving($plan->considered()->proved(), $plan->keys(), $plan->base());
        $carrying = Considering::of(
            $plan->considered()->carried(),
            Reach::nothing(Packages::of($trees)),
            $ledgers->defaultBranch()->proofs(),
            $ledgers->own()->proofs(),
        );
        $uncovered = Uncovered::from($this->settings->floors()->uncovered()->value);
        $ignoring = Ignoring::of($this->settings->ignores()->entries(), $this->setup->clock->now());
        $judge = Judge::of(
            $trees,
            $baseline,
            $this->reachOf($plan, $trees),
            $uncovered,
            $this->settings->triage()->timeouts(),
            $ignoring,
        );
        $fresh = Agreement::checked(
            $results->units(),
            $plan->keys(),
            $ledgers->defaultBranch()->proofs(),
            $ledgers->own()->proofs(),
        );
        $map = new Handoff($this->adapters->project)->forVerdict();
        $newest = $ledgers->newest();
        $commits = ChangesSince::commitsOf($results->unjudged(), $newest, $plan->base());
        $since = new Since($this->adapters, $this->settings)->of(...$commits);
        $unjudged = LeftUnjudged::of(
            $results->unjudged(),
            $newest,
            Carrying::against($plan->digests(), $plan->base(), $plan->names(), $map, $since),
        );
        $matrix = $this->matrixOf($plan, $map);
        $verdicts = $this->read(
            $matrix,
            $judge->judging($matrix)->trees(
                $fresh->and($proving->proved())->and($carrying->carried())->and($unjudged->results()),
            ),
        );
        $unfloored = Ratchet::unfloored($verdicts);
        $committed = $this->resolved($proving, $carrying, $this->committedBefore($plan));

        if ($committed instanceof CannotJudge) {
            return $committed;
        }

        $refused = count($unfloored) > 0 && $this->adapters->environment->inCi();
        $unrun = $this->missed($results->misses())->and($unjudged->failures());
        $verdict = $this->verdictOf(
            $plan,
            Lowering::against($committed, $baseline, $trees),
            $unrun
                ->and(Unfinished::failures($fresh->and($unjudged->results())))
                ->and($refused ? $this->unfloored($baseline, $verdicts) : Failures::none())
                ->and($ignoring->stale($verdicts, $unrun)),
            $judge,
            $verdicts,
            $ledgers->unread()
                ->and($results->warnings())
                ->and($results->checks()->warnings())
                ->and($since->warnings())
                ->and($ignoring->warnings()),
            $map,
            $matrix,
        );

        return new Assessed(
            $results->wereCutShort() ? $verdict->cutShort() : $verdict,
            $baseline,
            $ledgers,
            $proving->ownScopeProofs() + $carrying->ownScopeProofs(),
            $refused,
        );
    }

    /**
     * The trees, with their survivors of one cause clustered, each survivor
     * a weak test let through marked, and each removal whose callee may be
     * deleted, from the survivors' own files and their tests' files, each
     * read once (ADR-0022 decision 15, ADR-0025 decisions 5 to 7, 11 and 12).
     */
    private function read(KillMatrix $matrix, TreeVerdicts $judged): TreeVerdicts
    {
        $sources = Sources::ofSurvivors($judged, $this->adapters->project);
        $clustered = $judged->clustered($sources);
        $tests = TestFiles::read(
            Sources::of(Weakness::testFiles($clustered, $matrix), $this->adapters->project),
            Sources::of($this->adapters->runner->definitions(), $this->adapters->project),
        );
        $weak = Weakness::findings($clustered, $matrix, $sources, $tests);

        return $clustered->found($weak->and(Removals::findings($clustered, $matrix, $sources, $tests)));
    }

    /**
     * Why a CI run stops on trees held to no floor (ADR-0003, decision 9):
     * each tree, and the baseline the run measured, ready to commit. The run
     * judges, records and reports before it stops, so nothing it measured is
     * lost.
     */
    private function unfloored(Baseline $baseline, TreeVerdicts $verdicts): Failures
    {
        $file = $this->settings->floors()->baseline();

        return Ratchet::unflooredBecause(Ratchet::unfloored($verdicts), $file)->with(Failure::that(sprintf(
            self::MEASURED,
            $file->value(),
            BaselineFile::encode($baseline->raisedBy($verdicts)),
        )));
    }

    /**
     * @param Failures $lowered each floor the run lowers from the default branch's without its reason
     * @param Failures $missed  each held unit its holding tests miss lines of, and each unit a
     *                          time budget left unjudged
     * @param Warnings $shards  what the shards warn of
     */
    private function verdictOf(
        Plan $plan,
        Failures $lowered,
        Failures $missed,
        Judge $judge,
        TreeVerdicts $verdicts,
        Warnings $shards,
        CoverageMap|CannotJudge $map,
        KillMatrix $matrix,
    ): Verdict {
        $pullRequest = $plan->runOn()->isPullRequest();
        $newCode = $pullRequest
            ? $judge->newCode($verdicts, $this->settings->floors()->newCode())
            : NewCodeVerdicts::none();
        $failures = $pullRequest
            ? $this->pullRequestFailures($lowered, $verdicts)->and($missed)
            : $missed;
        $raised = $map instanceof CannotJudge
            ? $shards->with(Warning::that(sprintf(self::NO_MATRIX, $map->why())))
            : $this->hotPathsIn($plan, $map, $shards);

        return Verdict::of($verdicts)
            ->withNewCode($newCode)
            ->withReach($plan->considered()->reach())
            ->withMatrix($matrix)
            ->withWarnings($this->warnings($plan, $verdicts, $raised))
            ->withFailures($failures);
    }

    /** Why each held unit its holding tests miss lines of fails. */
    private function missed(HeldMisses $misses): Failures
    {
        $failures = Failures::none();

        foreach ($misses as $miss) {
            $failures = $failures->with(Failure::that($miss->why()));
        }

        return $failures;
    }

    /** In a pull request, a raise that must be committed with it, and a floor lowered without its reason. */
    private function pullRequestFailures(Failures $lowered, TreeVerdicts $verdicts): Failures
    {
        $required = $this->settings->floors()->improvement() === Improvement::Require
            ? Ratchet::required($verdicts, $this->settings->floors()->baseline())
            : Failures::none();

        return $required->and($lowered);
    }

    /**
     * The committed baseline, where every unit the plan took from a proof
     * still has it: a unit whose proof has gone would leave its tree judged
     * without it.
     */
    private function resolved(
        Proving $proving,
        Considering $carrying,
        Baseline|CannotJudge $committed,
    ): Baseline|CannotJudge {
        $vanished = [];

        foreach ([...$proving->toRun(), ...$carrying->considered()] as $unit) {
            $vanished[] = $unit->path()->value();
        }

        return $vanished === []
            ? $committed
            : CannotJudge::because(sprintf(self::VANISHED, implode(', ', $vanished)));
    }

    /** The baseline a floor lowered in a pull request is checked against; none outside one. */
    private function committedBefore(Plan $plan): Baseline|CannotJudge
    {
        $baselines = new Baselines($this->adapters, $this->settings->floors()->baseline());

        return $plan->runOn()->isPullRequest()
            ? $baselines->onDefaultBranch(Standing::planned($plan)->defaultBranch())
            : Baseline::none();
    }

    /**
     * Each tree held to no floor, the runner's own ignore markers the config
     * lets through, why the tests go by their ids where the plan holds no
     * names for them, and what the shards warn of.
     */
    private function warnings(Plan $plan, TreeVerdicts $verdicts, Warnings $shards): Warnings
    {
        $warnings = new RunnerMarkers($this->adapters, $this->settings)->allowed($plan);
        $names = $plan->names();
        $warnings = $names instanceof CannotJudge
            ? $warnings->with(Warning::that(sprintf(self::UNNAMED, $names->why())))
            : $warnings;

        foreach ($shards as $warning) {
            $warnings = $warnings->with($warning);
        }

        foreach ($this->adapters->environment->inCi() ? [] : Ratchet::unfloored($verdicts) as $tree) {
            $warnings = $warnings->with(Warning::that(sprintf(
                self::UNFLOORED,
                $tree->value(),
                $this->settings->floors()->baseline()->value(),
            )));
        }

        return $warnings;
    }

    /**
     * These warnings, and one for each file most of the suite runs through
     * that no held unit of the plan holds, past `holds.hotPath` of its tests
     * (ADR-0005, decision 11): each of its mutants runs most of the suite.
     */
    private function hotPathsIn(Plan $plan, CoverageMap $map, Warnings $warnings): Warnings
    {
        $units = [...$plan->considered()->proved(), ...$plan->considered()->carried()];

        foreach ($plan as $shard) {
            $units = [...$units, ...$shard->units()];
        }

        foreach ($this->settings->reach()->hotPaths()->in($map, Units::of(...$units)) as $hot) {
            $warnings = $warnings->with($hot);
        }

        return $warnings;
    }

    /**
     * The kill matrix of first killers (ADR-0014, decisions 9 to 11): over
     * the map the plan handed the verdict, which holds the lines of every
     * unit it considered, with the names the plan holds for the tests, and
     * why the runner's run holds first killers only. Without a map it holds
     * each mutant's killers alone.
     */
    private function matrixOf(Plan $plan, CoverageMap|CannotJudge $map): KillMatrix
    {
        $names = $plan->names();
        $matrix = KillMatrix::of(MatrixKind::FirstKiller, $map instanceof CoverageMap ? $map : CoverageMap::empty())
            ->cannotBeFull($this->adapters->runner->behaviour()->whyNotFull());

        return $names instanceof TestNames ? $matrix->named($names) : $matrix;
    }

    /** The lines each change added or modified, which the new-code floor judges. */
    private function reachOf(Plan $plan, Trees $trees): Reach
    {
        $reach = Reach::nothing(Packages::of($trees));

        foreach ($plan->considered()->changed() as $change) {
            $reach = $reach->withLines($change->path(), $change->lines());
        }

        return $reach;
    }

    /**
     * @param  list<Reporter> $reporters
     * @return list<string>   where each reporter wrote, or why it could not
     */
    private function reported(Verdict $verdict, array $reporters): array
    {
        $said = [];

        foreach ($reporters as $reporter) {
            $written = $reporter->report($verdict);
            $said[] = $written instanceof Written ? $written->said() : $written->why();
        }

        return $said;
    }

    private function recorded(
        Plan $plan,
        Results $results,
        Ledgers $ledgers,
        Verdict $verdict,
        int $ownScopeProofs,
    ): string|CannotJudge {
        $run = RunName::of($this->adapters->environment, Instant::at($this->setup->clock->now()), $plan->base());
        $unjudged = $verdict->trees()->mutants()->counts()->number(MutantJudgement::Unjudged);
        $passed = match (true) {
            $verdict->judgement() !== Judgement::Passed => CannotTell::because(self::FAILED),
            $unjudged > 0 => CannotTell::because(self::UNJUDGED),
            default => Passed::of($plan->commit(), $this->settings->ci()->check(), $ownScopeProofs),
        };
        $written = new Recorded($this->adapters)->write($plan, $results, $ledgers, $run, $passed);

        return match (true) {
            $written instanceof Written => $written->said(),
            $written instanceof CannotJudge => $written,
            $written instanceof NotWritten, $written instanceof ReadsOnly => $written->why(),
        };
    }
}
