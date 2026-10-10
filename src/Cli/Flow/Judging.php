<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use DateTimeImmutable;

use function implode;
use function is_array;

use NightWorksIO\MutationGate\Adapter\Project\PhpUnitSuite;
use NightWorksIO\MutationGate\Core\Assertion\TestFiles;
use NightWorksIO\MutationGate\Core\Assertion\Weakness;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Lowering;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Config\Improvement;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Plan\OwnOnly;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Proving;
use NightWorksIO\MutationGate\Core\Proof\Agreement;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Pruning\PrunedCarry;
use NightWorksIO\MutationGate\Core\Pruning\PruningAccount;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Removal\Removals;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\Carrying;
use NightWorksIO\MutationGate\Core\Verdict\ChangesSince;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\HeldSets;
use NightWorksIO\MutationGate\Core\Verdict\HeldTo;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\LeftUnjudged;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Ratchet;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Unfinished;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
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
    private const string FAILED = 'The verdict failed, so the commit did not pass.';

    /** Why each tree of a run of the security mutators alone is held to no floor (ADR-0021, decision 20). */
    private const string UNJUDGED_TREES = '--security judges only the security sets';

    /** Why a passing run of the security mutators alone records no commit as passed: its trees were not judged. */
    private const string SECURITY_ONLY = 'A run of the security mutators alone records no commit as passed.';

    /** Why each tree and security set of one suite's run is held to no floor (ADR-0025, decision 9). */
    private const string UNHELD = '--suite judges no floor';

    /** Why a passing run of one suite's tests alone records no commit as passed: no floor held it. */
    private const string SUITE_ONLY = 'A run of one suite\'s tests alone records no commit as passed.';

    /** Why a passing verdict's commit is not recorded as passed: a run from it must still reach what it left. */
    private const string UNJUDGED = 'The run left mutants unjudged, so its commit is not recorded as passed.';

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
        private HeldTo|NotGiven $heldTo = new NotGiven(),
    ) {
    }

    public function verdict(Plan $plan, Results $results): Judged|Invalid|CannotJudge
    {
        $reporters = $this->reporting->reporters($this->settings, $plan->runOn());

        if (! is_array($reporters)) {
            return $reporters;
        }

        $began = $this->setup->clock->now();
        $assessed = $this->grounded($plan, $results, Writing::from($this->settings->proofs()->write()->value));

        return $assessed instanceof Assessed
            ? $this->judged($plan, $results, $assessed, $reporters, $began)
            : $assessed;
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
    private function judged(
        Plan $plan,
        Results $results,
        Assessed $assessed,
        array $reporters,
        DateTimeImmutable $began,
    ): Judged|CannotJudge {
        $now = $this->setup->clock->now();
        $run = RunName::of($this->adapters->environment, Instant::at($now), $plan->base())->id();
        $timings = VerdictTimings::of($run, $results, $began, $now, $this->settings->shards()->setup());
        $verdict = $assessed->verdict->withAccount($assessed->verdict->account()->withTimings($timings));
        $recorded = $this->recorded($plan, $results, $assessed->ledgers, $verdict, $assessed->ownScopeProofs);

        if ($recorded instanceof CannotJudge) {
            return $recorded;
        }

        $judged = new Judged(
            $verdict,
            [$recorded, ...$this->kept($assessed->ledgers), ...Reported::by($verdict, $reporters)],
            $assessed->baseline,
        );

        return $assessed->refused ? $judged->refusing() : $judged;
    }

    /**
     * The coverage map the plan handed on, kept beside the default branch's
     * ledger for a run on that branch (ADR-0023, decision 2): where, or why it
     * could not be; nothing for a run that keeps none.
     *
     * @return list<string>
     */
    private function kept(Ledgers $ledgers): array
    {
        $kept = new KeptCoverage($this->adapters, $this->settings, $this->setup)->keep($ledgers->access());

        return match (true) {
            $kept instanceof Written => [$kept->said()],
            $kept instanceof NotWritten => [$kept->why()],
            default => [],
        };
    }

    /** A plan's results judged over the trees and the committed baseline, or why either cannot be read. */
    private function grounded(Plan $plan, Results $results, Writing $writing): Assessed|CannotJudge
    {
        $trees = $this->adapters->trees->trees();
        $baseline = new Baselines($this->adapters, $this->settings->floors()->baseline())->committed();

        return match (true) {
            $trees instanceof CannotJudge => $trees,
            $baseline instanceof CannotJudge => $baseline,
            default => $this->assessed($plan, $results, $this->held($trees), $baseline, $writing),
        };
    }

    /**
     * The trees, each exempt in a run of the security mutators alone, which
     * judges none of them, and in a run of one suite's tests alone, which
     * holds no floor.
     */
    private function held(Trees $trees): Trees
    {
        $why = match (true) {
            $this->adapters->isSecurityOnly() => Exempt::because(self::UNJUDGED_TREES),
            $this->adapters->isSuiteOnly() => Exempt::because(self::UNHELD),
            default => NotGiven::value(),
        };

        if (! $why instanceof Exempt) {
            return $trees;
        }

        $exempt = Trees::none();

        foreach ($trees as $tree) {
            $exempt = $exempt->with($tree->declaring($why));
        }

        return $exempt;
    }

    /**
     * Each package's security set, exempt in a run of one suite's tests alone
     * that does not make mutants with the security mutators alone, which
     * holds no floor (ADR-0025, decision 9).
     */
    private function secured(SecurityVerdicts $security): SecurityVerdicts
    {
        if ($this->adapters->isSecurityOnly() || ! $this->adapters->isSuiteOnly()) {
            return $security;
        }

        $exempt = [];

        foreach ($security as $set) {
            $exempt[] = $set->exempting(Exempt::because(self::UNHELD));
        }

        return SecurityVerdicts::of(...$exempt);
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
        $kind = $plan->briefing()->matrix();
        $proving = $ledgers->proving($plan->considered()->proved(), $plan->keys(), $plan->base(), $kind);
        $carrying = $ledgers->considering(
            $plan->considered()->carried(),
            Reach::nothing(Packages::of($trees)),
            $kind,
            OwnOnly::of($plan->considered()->carriedOwn()->paths(), $plan->digests()),
        );
        $uncovered = Uncovered::from($this->settings->floors()->uncovered()->value);
        $ignoring = Ignoring::of($this->settings->ignores()->entries(), $this->setup->clock->now());
        $judge = Judge::of(
            $trees,
            $baseline,
            $plan->considered()->lines(Packages::of($trees)),
            $uncovered,
            $this->settings->triage()->timeouts(),
            $ignoring,
        );
        $judge = $this->adapters->isSecurityOnly() ? $judge->onlyMadeBy($this->adapters->security) : $judge;
        $newest = $ledgers->newest();
        $fresh = PrunedCarry::of($plan->considered()->pruned(), $newest, $plan->digests())->into(Agreement::checked(
            $results->units(),
            $plan->keys(),
            $ledgers->defaultBranch()->proofs(),
            $ledgers->own()->proofs(),
        ));
        $map = new Handoff($this->adapters->project, Handoff::limits())->forVerdict();
        $stop = $results->stopped();
        $commits = ChangesSince::commitsOf($results->unjudged()->and($stop->units()), $newest, $plan->base());
        $since = new Since($this->adapters, $this->settings)->of(...$commits);
        $counted = Carrying::against($plan->digests(), $plan->base(), $plan->names(), $map, $since);
        $unjudged = LeftUnjudged::of($results->unjudged(), $newest, $counted);
        $doomed = $stop->doomed();
        $stopped = $doomed instanceof Doomed
            ? LeftUnjudged::stoppedBy($doomed, $stop->units(), $newest, $counted)->results()
            : UnitResults::none();
        $matrix = $this->matrixOf($plan, $map);
        $judged = $fresh->and($proving->proved())->and($carrying->carried())->and($unjudged->results())->and($stopped);
        $equivalents = new StaticEquivalence($this->adapters, $this->settings)->among($judged);
        $verdicts = $this->read($matrix, $judge->judging($matrix)->proving($equivalents->proven)->trees($judged));
        $security = $this->secured(
            $judge->security($verdicts, $this->adapters->security, $this->settings->floors()->security()),
        );
        $unfloored = count(Ratchet::unfloored($verdicts)) + count(Ratchet::securityUnfloored($security));
        $committed = $this->resolved($proving, $carrying, $this->committedBefore($plan));

        if ($committed instanceof CannotJudge) {
            return $committed;
        }

        $refused = $unfloored > 0 && $this->adapters->environment->inCi();
        $unrun = $this->missed($results->misses())->and($unjudged->failures())->and($stop->failures());
        $verdict = $this->verdictOf(
            $plan,
            Lowering::against($committed, $baseline, $trees),
            $unrun
                ->and(Unfinished::failures($fresh->and($unjudged->results())))
                ->and($refused ? $this->unfloored($baseline, $verdicts, $security) : Failures::none())
                ->and($ignoring->stale($verdicts, $unrun, $this->adapters->narrowing->mutators())),
            $judge,
            $verdicts,
            $security,
            $ledgers->unread()
                ->and($results->warnings())
                ->and($results->checks()->warnings())
                ->and($since->warnings())
                ->and($ignoring->warnings())
                ->and($equivalents->warnings)
                ->and($ignoring->redundant($verdicts, $equivalents->proven)),
            $map,
            $matrix,
        );

        $pruning = $this->settings->reach()->pruning();
        $verdict = $verdict->withAccount($verdict->account()->withPruning(PruningAccount::of(
            $plan->considered()->pruned(),
            $ledgers->own()->and($ledgers->defaultBranch())->survival(),
            $this->settings->runner()->name(),
            $pruning->window(),
            $pruning->audit(),
            $fresh,
        )));

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
     * Why a CI run stops on trees and security sets held to no floor
     * (ADR-0003 decision 9, ADR-0021 decision 17): each tree and set, and the
     * baseline the run measured, ready to commit. The run judges, records and
     * reports before it stops, so nothing it measured is lost.
     */
    private function unfloored(Baseline $baseline, TreeVerdicts $verdicts, SecurityVerdicts $security): Failures
    {
        $file = $this->settings->floors()->baseline();

        return Ratchet::unflooredBecause(Ratchet::unfloored($verdicts), $file)
            ->and(Ratchet::securityUnflooredBecause(Ratchet::securityUnfloored($security), $file))
            ->with(Failure::that(sprintf(
                self::MEASURED,
                $file->value(),
                BaselineFile::encode($baseline->raisedBy($verdicts, $security)),
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
        SecurityVerdicts $security,
        Warnings $shards,
        CoverageMap|CannotJudge $map,
        KillMatrix $matrix,
    ): Verdict {
        $pullRequest = $plan->runOn()->isPullRequest();
        $named = match (true) {
            $this->adapters->isSecurityOnly() => HeldTo::Security,
            $this->adapters->isSuiteOnly() => HeldTo::Nothing,
            default => $this->heldTo,
        };
        $heldTo = HeldTo::named($named, pullRequest: $pullRequest);
        $newCode = $heldTo->holdsNewCode()
            ? $judge->newCode($verdicts, $this->settings->floors()->newCode())
            : NewCodeVerdicts::none();
        $failures = $pullRequest
            ? $this->pullRequestFailures($lowered, $verdicts, $security)->and($missed)
            : $missed;
        $warnings = new VerdictWarnings($this->adapters, $this->settings);

        return Verdict::of($verdicts, $heldTo)
            ->withSets(HeldSets::of($newCode, $security))
            ->withReach($plan->considered()->reach())
            ->withMatrix($matrix)
            ->withWarnings($warnings->of($plan, $verdicts, $shards, $map)->and($warnings->unfloored($security)))
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
    private function pullRequestFailures(
        Failures $lowered,
        TreeVerdicts $verdicts,
        SecurityVerdicts $security,
    ): Failures {
        $file = $this->settings->floors()->baseline();
        $required = $this->settings->floors()->improvement() === Improvement::Require
            ? Ratchet::required($verdicts, $file)->and(Ratchet::securityRequired($security, $file))
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
     * The kill matrix of the kind the plan records (ADR-0014, decisions 7
     * and 9 to 11): over the map the plan handed the verdict, which holds the
     * lines of every unit it considered, with the names the plan holds for
     * the tests, the suites the PHPUnit config declares them in, and why a
     * matrix of first killers holds no more. Without a map it holds each
     * mutant's killers alone.
     */
    private function matrixOf(Plan $plan, CoverageMap|CannotJudge $map): KillMatrix
    {
        $names = $plan->names();
        $configured = Suite::configured($this->adapters->project);
        $matrix = KillMatrix::of($plan->briefing()->matrix(), $map instanceof CoverageMap ? $map : CoverageMap::empty())
            ->cannotBeFull($this->adapters->runner->behaviour()->whyNotFull())
            ->grouping($configured instanceof PhpUnitSuite ? $configured->suites() : DeclaredSuites::none());

        return $names instanceof TestNames ? $matrix->named($names) : $matrix;
    }

    private function recorded(
        Plan $plan,
        Results $results,
        Ledgers $ledgers,
        Verdict $verdict,
        int $ownScopeProofs,
    ): string|CannotJudge {
        $at = Instant::at($this->setup->clock->now());
        $run = RunName::of($this->adapters->environment, $at, $plan->base())->recording($plan->briefing()->matrix());
        $unjudged = $verdict->trees()->mutants()->counts()->number(MutantJudgement::Unjudged);
        $passed = match (true) {
            $verdict->judgement() !== Judgement::Passed => CannotTell::because(self::FAILED),
            $unjudged > 0 => CannotTell::because(self::UNJUDGED),
            $this->adapters->isSecurityOnly() => CannotTell::because(self::SECURITY_ONLY),
            $this->adapters->isSuiteOnly() => CannotTell::because(self::SUITE_ONLY),
            $plan->briefing()->isOnOwnScopeCoverage()
                => Passed::of($plan->commit(), $this->settings->ci()->check(), $ownScopeProofs)
                    ->passedAt($at)
                    ->onOwnScopeCoverage(),
            default => Passed::of($plan->commit(), $this->settings->ci()->check(), $ownScopeProofs)->passedAt($at),
        };
        $judged = $this->adapters->changes->judged($plan->commit());
        $lastRun = $judged instanceof JudgedCommit
            ? LastRun::of($judged, $this->settings->ci()->check(), $plan->briefing()->profile())
            : $judged;
        $written = new Recorded($this->adapters, $this->settings)
            ->write($plan, $results, $ledgers, $run, $passed, $lastRun);

        return match (true) {
            $written instanceof Written => $written->said(),
            $written instanceof CannotJudge => $written,
            $written instanceof NotWritten, $written instanceof ReadsOnly => $written->why(),
        };
    }
}
