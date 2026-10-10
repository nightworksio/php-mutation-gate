<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function is_array;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

/**
 * The verdict of a plan that ran nothing because the verdict of the newest
 * commit of its scope that passed stands ({@see Unchanged}): it passes, says
 * why, and records the commit the plan was made on as passed; unless an
 * ignore that applied when that commit passed has expired since the plan
 * was made, when it cannot judge.
 */
final readonly class UnchangedVerdict
{
    private const string EXPIRED
        = 'The ignore of %s has expired since %s passed, so its verdict no longer stands. Plan the run again.';

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private Setup $setup,
        private Reporting $reporting,
    ) {
    }

    /** The verdict that stands: it judges no tree, passes, and says why. */
    public static function verdictOf(Unchanged $unchanged): Verdict
    {
        return Verdict::of(TreeVerdicts::none())->withReach(Reasons::of($unchanged->reason()));
    }

    /** The verdict that stands for a plan, reported and recorded; or why it no longer stands, or cannot be told. */
    public function of(Plan $plan, Unchanged $unchanged): Judged|Invalid|CannotJudge
    {
        $now = $this->setup->clock->now();
        $expired = $unchanged->expiredBy($this->settings->ignores()->entries(), $now);
        $baseline = new Baselines($this->adapters, $this->settings->floors()->baseline())->committed();
        $reporters = $this->reporting->reporters($this->settings, $plan->runOn());

        return match (true) {
            $expired instanceof Ignored => CannotJudge::because(
                sprintf(self::EXPIRED, $expired->named(), $unchanged->base()->commit()->name()),
            ),
            $baseline instanceof CannotJudge => $baseline,
            ! is_array($reporters) => $reporters,
            default => $this->recorded($plan, $unchanged, $baseline, Instant::at($now), $reporters),
        };
    }

    /**
     * The verdict that stands, reported and with the commit the plan was made
     * on recorded as passed at this instant.
     *
     * @param list<Reporter> $reporters
     */
    private function recorded(
        Plan $plan,
        Unchanged $unchanged,
        Baseline $baseline,
        Instant $at,
        array $reporters,
    ): Judged {
        $ledgers = Ledgers::read(
            $this->adapters->proofs,
            Standing::planned($plan),
            Writing::from($this->settings->proofs()->write()->value),
        );
        $recorded = new Recorded($this->adapters, $this->settings)->passing(
            $ledgers,
            $unchanged->passing($plan->commit(), $at),
        );
        $verdict = self::verdictOf($unchanged);
        $said = Reported::by($verdict, $reporters);

        return new Judged(
            $verdict,
            [$recorded instanceof Written ? $recorded->said() : $recorded->why(), ...$said],
            $baseline,
        );
    }
}
