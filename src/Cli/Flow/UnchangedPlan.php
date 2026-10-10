<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Plan\Unchanging;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;

use function sprintf;

/**
 * The plan that runs nothing, made before any coverage run, where the verdict
 * of the newest commit of the run's scope whose verdict passed stands for
 * what is there now ({@see Unchanging}): only for a standard run whose change
 * is read since that commit, as by default. Its keys are built on nothing, as
 * it keys nothing and establishes no proof.
 */
final readonly class UnchangedPlan
{
    private const string NOT_STANDARD = 'A run narrowed or recording more than first killers plans what it judges.';

    /** What the plan says of the coverage it did not measure. */
    private const string UNMEASURED = 'Measured no coverage: %s';

    private const string NOT_SINCE_PASSED = 'The run reads its change since another commit than the last that passed.';

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /**
     * The plan that runs nothing for a run of this mode recording this much,
     * judged against these ledgers; or why the run plans what it judges.
     */
    public function of(Inventory $inventory, Ledgers $ledgers, Mode $mode, MatrixKind $matrix): PlanMade|Reason
    {
        $briefing = $this->adapters->briefing(Briefing::standard()->recording($matrix));

        if (! $mode->isSinceLastPassed()) {
            return Reason::that(self::NOT_SINCE_PASSED);
        }

        if (! $briefing->profile()->equals(RunProfile::standard())) {
            return Reason::that(self::NOT_STANDARD);
        }

        $passed = $ledgers->lastPassed();
        $changes = $passed instanceof Passed ? $this->adapters->changes->changesFrom($passed->commit()) : $passed;
        $unchanged = Unchanging::within(
            Reached::layout($this->adapters, $this->settings, $inventory->suite),
            Packages::of($inventory->trees),
            $this->settings->floors()->baseline(),
        )->since(
            $passed,
            $changes,
            $this->settings->ci()->check(),
            $this->settings->ignores()->entries(),
            $this->setup->clock->now(),
        );

        return $unchanged instanceof Unchanged ? $this->planned($inventory, $briefing, $unchanged) : $unchanged;
    }

    private function planned(Inventory $inventory, Briefing $briefing, Unchanged $unchanged): PlanMade
    {
        $plan = Plan::of($inventory->standing->head(), Digest::sha256Of(''), Keys::none(), Shards::none())
            ->on($inventory->standing->runOn())
            ->briefed($briefing->unchangedSince($unchanged))
            ->considering(Considered::everything()->reaching(Changes::none(), Reasons::of($unchanged->reason())));

        return PlanMade::of($plan, sprintf(self::UNMEASURED, $unchanged->reason()->text()));
    }
}
