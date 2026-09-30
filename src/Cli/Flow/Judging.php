<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;
use function is_array;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Lowering;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Improvement;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Ratchet;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
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
 * and a floor lowered without its reason. Then the reports, and the ledger.
 * A tree held to no floor stops a run in CI, and is warned of elsewhere.
 */
final readonly class Judging
{
    private const string UNFLOORED = '%s has no floor yet. Run mutation-gate baseline --write and commit %s.';

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private Setup $setup,
        private Reporting $reporting,
    ) {
    }

    public function verdict(Plan $plan, Results $results): Judged|Invalid|CannotJudge
    {
        $trees = $this->adapters->trees->trees();
        $baseline = new Baselines($this->adapters, $this->settings->floors()->baseline())->committed();
        $reporters = $this->reporting->reporters($this->settings, $plan->runOn());

        return match (true) {
            $trees instanceof CannotJudge => $trees,
            $baseline instanceof CannotJudge => $baseline,
            ! is_array($reporters) => $reporters,
            default => $this->judged($plan, $results, $trees, $baseline, $reporters),
        };
    }

    /** @param list<Reporter> $reporters */
    private function judged(
        Plan $plan,
        Results $results,
        Trees $trees,
        Baseline $baseline,
        array $reporters,
    ): Judged|CannotJudge {
        $writing = Writing::from($this->settings->proofs()->write()->value);
        $ledgers = Ledgers::read($this->adapters->proofs, Standing::planned($plan), $writing);
        $proving = $ledgers->proving($plan->proved(), $plan->keys(), $plan->base());
        $carrying = Considering::of(
            $plan->carried(),
            Reach::nothing(Packages::of($trees)),
            $ledgers->defaultBranch()->proofs(),
            $ledgers->own()->proofs(),
        );
        $uncovered = Uncovered::from($this->settings->floors()->uncovered()->value);
        $judge = Judge::of($trees, $baseline, $this->reachOf($plan, $trees), $uncovered);
        $verdicts = $judge->trees($results->units()->and($proving->proved())->and($carrying->carried()));
        $unfloored = Ratchet::unfloored($verdicts);

        if (count($unfloored) > 0 && $this->adapters->environment->inCi()) {
            return CannotJudge::because(
                Ratchet::unflooredBecause($unfloored, $this->settings->floors()->baseline())->text(),
            );
        }

        $verdict = $this->verdictOf($plan, $trees, $baseline, $judge, $verdicts);
        $ownScopeProofs = $proving->ownScopeProofs() + $carrying->ownScopeProofs();

        $recorded = $this->recorded($plan, $results, $ledgers, $verdict, $ownScopeProofs);

        return $recorded instanceof CannotJudge
            ? $recorded
            : new Judged($verdict, [$recorded, ...$this->reported($verdict, $reporters)], $baseline);
    }

    private function verdictOf(
        Plan $plan,
        Trees $trees,
        Baseline $baseline,
        Judge $judge,
        TreeVerdicts $verdicts,
    ): Verdict {
        $pullRequest = $plan->runOn()->isPullRequest();
        $newCode = $pullRequest
            ? $judge->newCode($verdicts, $this->settings->floors()->newCode())
            : NewCodeVerdicts::none();
        $failures = $pullRequest
            ? $this->pullRequestFailures($plan, $trees, $baseline, $verdicts)
            : Failures::none();

        return Verdict::of($verdicts)
            ->withNewCode($newCode)
            ->withReach($plan->reach())
            ->withWarnings($this->unflooredWarnings($verdicts))
            ->withFailures($failures);
    }

    /** In a pull request, a raise that must be committed with it, and a floor lowered without its reason. */
    private function pullRequestFailures(
        Plan $plan,
        Trees $trees,
        Baseline $baseline,
        TreeVerdicts $verdicts,
    ): Failures {
        $baselineFile = $this->settings->floors()->baseline();
        $required = $this->settings->floors()->improvement() === Improvement::Require
            ? Ratchet::required($verdicts, $baselineFile)
            : Failures::none();
        $defaultBranch = Standing::planned($plan)->defaultBranch();
        $onDefaultBranch = new Baselines($this->adapters, $baselineFile)->onDefaultBranch($defaultBranch);

        return $required->and(Lowering::against($onDefaultBranch, $baseline, $trees));
    }

    private function unflooredWarnings(TreeVerdicts $verdicts): Warnings
    {
        $warnings = Warnings::none();

        foreach (Ratchet::unfloored($verdicts) as $tree) {
            $warnings = $warnings->with(Warning::that(sprintf(
                self::UNFLOORED,
                $tree->value(),
                $this->settings->floors()->baseline()->value(),
            )));
        }

        return $warnings;
    }

    /** The lines each change added or modified, which the new-code floor judges. */
    private function reachOf(Plan $plan, Trees $trees): Reach
    {
        $reach = Reach::nothing(Packages::of($trees));

        foreach ($plan->changed() as $change) {
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
        $passed = $verdict->judgement() === Judgement::Passed
            ? Passed::of($plan->commit(), $this->settings->ci()->check(), $ownScopeProofs)
            : CannotTell::because('The verdict failed, so the commit did not pass.');
        $written = new Recorded($this->adapters)->write($plan, $results, $ledgers, $run, $passed);

        return match (true) {
            $written instanceof Written => $written->said(),
            $written instanceof CannotJudge => $written,
            $written instanceof NotWritten, $written instanceof ReadsOnly => $written->why(),
        };
    }
}
