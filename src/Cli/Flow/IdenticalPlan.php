<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;

use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\GateRelease;
use NightWorksIO\MutationGate\Core\Proof\Key\RunInputs;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Passes;
use NightWorksIO\MutationGate\Core\Proof\ReusablePass;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Time\Instant;

use function sprintf;

/**
 * The plan that runs nothing, made before any coverage run, where a passed
 * verdict whose run read inputs identical to this run's stands for it
 * (ADR-0198): one the run's own scope or the default branch records, or the
 * merged pull request's that GitHub names, under the run's own check, while
 * no ignore that applied when it passed has expired since; and otherwise the
 * one {@see UnchangedPlan} makes. `reuse.identical` says where a verdict is
 * reused; a run from a fork reads no pass its own scope records unless
 * `reuse.forks` says; a run that considers every unit reuses only a pass
 * whose run carried no unit's result; and a run narrowed, sampled, taking no
 * proof or recording more than first killers reuses none. Every plan it
 * makes says the digest of the run's inputs.
 *
 * @internal
 */
final readonly class IdenticalPlan
{
    /** The variables a runner's image names itself by, where it is a hosted CI's. */
    private const array IMAGE = ['ImageOS', 'ImageVersion', 'RUNNER_OS', 'RUNNER_ARCH'];

    private const string OFF = '`reuse.identical` reuses no verdict on this run.';

    private const string NOT_STANDARD
        = 'A run narrowed, sampled, taking no proof or recording more than first killers judges what it plans.';

    private const string NONE = 'No passed verdict the run may reuse read inputs identical to its own (%s).';

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /**
     * The digest of every input the run reads (ADR-0198), from the digest of what every proof's key reads first; or
     * why it cannot be read.
     */
    public function inputs(Inventory $inventory, Digest|CannotJudge $reads): Digest|CannotJudge
    {
        return $reads instanceof CannotJudge ? $reads : RunInputs::of(
            $reads,
            $inventory->files,
            GateRelease::of($this->setup->gate)->value(),
            $this->valuesOf(self::IMAGE),
            $this->valuesOf([...$this->settings->ci()->reuse()->environment()]),
        );
    }

    /**
     * The plan that runs nothing for a run of this mode recording this much,
     * whose inputs have this digest, judged against these ledgers: a verdict
     * reused, or one that stands as nothing the gate judges changed; or why
     * the run plans what it judges.
     */
    public function standing(
        Inventory $inventory,
        Ledgers $ledgers,
        Mode $mode,
        MatrixKind $matrix,
        Digest|CannotJudge $inputs,
    ): PlanMade|Reason {
        $reused = $this->reused($inventory, $ledgers, $mode, $matrix, $inputs);
        $unchanged = $reused instanceof PlanMade
            ? $reused
            : new UnchangedPlan($this->adapters, $this->settings, $this->setup)
                ->of($inventory, $ledgers, $mode, $matrix);

        return $unchanged instanceof PlanMade ? $unchanged->reading($inputs) : $unchanged;
    }

    /** A plan made, briefed with the digest of every input its run reads; as it is where no plan was made. */
    public static function briefed(PlanMade|CannotJudge $made, Digest|CannotJudge $inputs): PlanMade|CannotJudge
    {
        return $made instanceof PlanMade ? $made->reading($inputs) : $made;
    }

    /** The plan that reuses a verdict whose inputs are identical; or why none is reused. */
    private function reused(
        Inventory $inventory,
        Ledgers $ledgers,
        Mode $mode,
        MatrixKind $matrix,
        Digest|CannotJudge $inputs,
    ): PlanMade|Reason {
        $briefing = $this->adapters->briefing(Briefing::standard()->recording($matrix));
        $why = $this->whyNot($briefing, $mode, $inventory, $ledgers, $inputs);
        $standing = $why instanceof Digest
            ? $this->firstStanding($this->passes($inventory, $ledgers), $why, $mode->isFull())
            : $why;

        return $standing instanceof Unchanged ? $this->planned($inventory, $briefing, $standing) : $standing;
    }

    /** Why the run reuses no verdict, before any pass is read; nothing where it may. */
    private function whyNot(
        Briefing $briefing,
        Mode $mode,
        Inventory $inventory,
        Ledgers $ledgers,
        Digest|CannotJudge $inputs,
    ): Reason|Digest {
        $onDefault = $inventory->standing->runOn()->scope() instanceof Scope && ! $ledgers->readsOwnScope();

        return match (true) {
            $inputs instanceof CannotJudge => Reason::that($inputs->why()),
            ! $this->settings->ci()->reuse()->identical()->reusesOn($onDefault) => Reason::that(self::OFF),
            ! $briefing->profile()->equals(RunProfile::standard()), $mode->isSampled(), $mode->isUnproven()
                => Reason::that(self::NOT_STANDARD),
            default => $inputs,
        };
    }

    /**
     * The verdict of the first of these passes that stands for the run; or why none does.
     *
     * @param list<ReusablePass> $passes
     */
    private function firstStanding(array $passes, Digest $inputs, bool $full): Unchanged|Reason
    {
        foreach ($passes as $pass) {
            $unchanged = $this->standingOn($pass, $inputs, $full);

            if ($unchanged instanceof Unchanged) {
                return $unchanged;
            }
        }

        return Reason::that(sprintf(self::NONE, RunInputs::short($inputs)));
    }

    /**
     * Every pass the run may reuse, the newest of each source first: its own
     * scope's, but on a fork's pull request unless `reuse.forks` says; the
     * default branch's, off it; and the merged pull request's GitHub names.
     *
     * @return list<ReusablePass>
     */
    private function passes(Inventory $inventory, Ledgers $ledgers): array
    {
        $runOn = $inventory->standing->runOn();
        $scope = $runOn->scope();
        $default = $inventory->standing->defaultBranch();
        $readsOwn = $scope instanceof Scope && (! $runOn->isFromAFork() || $this->settings->ci()->reuse()->forks());
        $changes = $this->adapters->changes;

        return [
            ...$readsOwn ? $this->inScope($ledgers->written()->runs()->reusable(), $scope) : [],
            ...$ledgers->readsOwnScope() ? $this->inScope($ledgers->defaultBranch()->runs()->reusable(), $default) : [],
            ...$changes instanceof PassedPullRequests ? $changes->reusable() : [],
        ];
    }

    /** @return list<ReusablePass> */
    private function inScope(Passes $passes, Scope $scope): array
    {
        return array_map(
            static fn(Passed $passed): ReusablePass => ReusablePass::inScope($passed, $scope),
            [...$passes],
        );
    }

    /**
     * The verdict of a pass that stands for the run: its run read the same
     * inputs, under the run's own check, and it says when; for a run that
     * considers every unit, its run carried no unit's result; and no ignore
     * that applied when it passed has expired since. Nothing where any is not
     * so.
     */
    private function standingOn(ReusablePass $pass, Digest $inputs, bool $full): Unchanged|NotGiven
    {
        $passed = $pass->passed();
        $at = $passed->at();
        $judging = $passed->judging();
        $stands = $at instanceof Instant
            && $judging->readSame($inputs)
            && $passed->check() === $this->settings->ci()->check()
            && (! $full || $judging->isWhole());
        $unchanged = $stands ? Unchanged::reusing($pass, $at) : NotGiven::value();
        $expired = $unchanged instanceof Unchanged
            ? $unchanged->expiredBy($this->settings->ignores()->entries(), $this->setup->clock->now())
            : NotGiven::value();

        return $expired instanceof Ignored ? NotGiven::value() : $unchanged;
    }

    private function planned(Inventory $inventory, Briefing $briefing, Unchanged $unchanged): PlanMade
    {
        $plan = Plan::of($inventory->standing->head(), Digest::ofNothing(), Keys::none(), Shards::none())
            ->on($inventory->standing->runOn())
            ->briefed($briefing->unchangedSince($unchanged))
            ->considering(Considered::everything()->reaching(Changes::none(), Reasons::of($unchanged->reason())));

        return PlanMade::of($plan, sprintf(UnchangedPlan::UNMEASURED, $unchanged->reason()->text()));
    }

    /**
     * @param  list<string>          $names
     * @return array<string, string> the value of each of these variables the environment sets, by name
     */
    private function valuesOf(array $names): array
    {
        $values = [];

        foreach ($names as $name) {
            if ($this->adapters->environment->has($name)) {
                $values[$name] = $this->adapters->environment->valueOf($name);
            }
        }

        return $values;
    }
}
