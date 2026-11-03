<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Improvement;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\ReachingNothing;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * The verdict that stands for a plan that runs no unit, where the commit its
 * change is read since passed on the default branch ({@see ReachingNothing}):
 * only for a standard run that reads its change since a ref, takes no result
 * of its own scope, and, in a pull request or merge group, is not held to
 * commit each raise with it.
 *
 * @internal
 */
final readonly class NothingReached
{
    private const string NOT_STANDARD = 'A run narrowed or recording more than first killers judges what it carries.';

    private const string OWN_SCOPE = 'The plan takes %d results of the run\'s own scope.';

    private const string RAISES
        = 'A proposed change must commit each raise with it, so its verdict judges what it carries.';

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /**
     * What the plan of these shards tells the verdict: the commit whose
     * verdict stands, where it cuts none ({@see of()}); nothing besides
     * elsewhere.
     */
    public function briefing(
        Shards|CannotJudge $shards,
        Inventory $inventory,
        Ledgers $ledgers,
        ChangeBase|CannotTell $base,
        RunProfile $kind,
        int $ownScopeProofs,
    ): Briefing {
        $stands = $shards instanceof Shards && count($shards) === 0
            ? $this->of($inventory, $ledgers, $base, $kind, $ownScopeProofs)
            : NotGiven::value();

        return $stands instanceof Unchanged ? Briefing::standard()->unchangedSince($stands) : Briefing::standard();
    }

    /**
     * The verdict that stands for a plan that runs no unit, of a run of this
     * kind, reading its change since this base, that takes this many results
     * of its own scope; or why its verdict judges what it carries.
     */
    private function of(
        Inventory $inventory,
        Ledgers $ledgers,
        ChangeBase|CannotTell $base,
        RunProfile $kind,
        int $ownScopeProofs,
    ): Unchanged|Reason {
        $proposed = $inventory->standing->runOn()->isProposed();

        return match (true) {
            ! $base instanceof ChangeBase => Reason::that($base->why()),
            ! $kind->equals(RunProfile::standard()) => Reason::that(self::NOT_STANDARD),
            $ownScopeProofs > 0 => Reason::that(sprintf(self::OWN_SCOPE, $ownScopeProofs)),
            $proposed && $this->settings->floors()->improvement() === Improvement::Require
                => Reason::that(self::RAISES),
            default => $this->standing($inventory->trees, $ledgers, $base),
        };
    }

    private function standing(Trees $trees, Ledgers $ledgers, ChangeBase $base): Unchanged|Reason
    {
        $default = $ledgers->defaultBranch();
        $passed = $default->runs()->passed();

        return ReachingNothing::within($trees, $this->settings->floors()->baseline(), $this->settings->ci()->check())
            ->stands(
                $passed,
                $default->proofs(),
                $base->ref(),
                $passed instanceof Passed ? $this->adapters->changes->changesFrom($passed->commit()) : $passed,
                $this->adapters->changes->changesSince($base->ref()),
                $this->settings->ignores()->entries(),
                $this->setup->clock->now(),
            );
    }
}
