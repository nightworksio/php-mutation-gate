<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\Repository;

use function sprintf;

/**
 * Where a run stands: the commit its checkout is at, and what it runs on.
 * The CI says what it can of the run; where it cannot, git's branch is the
 * run's ref, and a detached `HEAD` has none. The default branch is
 * `ci.defaultBranch` where the config sets it, then the CI's, then the one
 * `origin/HEAD` points at, and `main` where nothing names one.
 */
final readonly class Standing
{
    private const string MAIN = 'main';

    private const string FETCHED = 'refs/remotes/origin/%s';

    private const string NO_HEAD = 'The commit HEAD is at cannot be read, so the run cannot be tied to one. %s';

    private function __construct(private Revision $head, private RunOn $runOn)
    {
    }

    public static function of(CiPlan $ci, Repository $repository, string|Absent $defaultBranch): self|CannotJudge
    {
        $head = $repository->head();

        if ($head instanceof CannotTell) {
            return CannotJudge::because(sprintf(self::NO_HEAD, $head->why()));
        }

        $run = self::runOf($ci->runOn(), $repository);
        $default = self::defaultBranchOf($defaultBranch, $run, $repository);

        return new self($head, $run->withDefaultBranch($default)->atCheckout($head));
    }

    /** Where the plan says the run stands, which every shard and the verdict take from it. */
    public static function planned(Plan $plan): self
    {
        return new self($plan->commit(), $plan->runOn());
    }

    /** The commit the checkout is at. */
    public function head(): Revision
    {
        return $this->head;
    }

    /** The run's ref, whether it is a pull request, and the default branch, which is always known here. */
    public function runOn(): RunOn
    {
        return $this->runOn;
    }

    /** The default branch's scope. */
    public function defaultBranch(): Scope
    {
        $default = $this->runOn->defaultBranch();

        return $default instanceof Scope ? $default : Scope::branch(self::MAIN);
    }

    /** The default branch as a checkout that fetched it holds it, which a pull request is read against. */
    public function fetchedDefaultBranch(): Revision
    {
        return self::fetched($this->defaultBranch());
    }

    /** A branch as a checkout that fetched it from `origin` holds it. */
    public static function fetched(Scope $branch): Revision
    {
        return Revision::ref(sprintf(self::FETCHED, $branch->name()));
    }

    private static function runOf(RunOn|CannotTell $said, Repository $repository): RunOn
    {
        if ($said instanceof RunOn) {
            return $said;
        }

        $branch = $repository->branch();
        $unnamed = CannotTell::because('The CI does not name the default branch.');

        return $branch instanceof Scope ? RunOn::at($branch, $unnamed) : RunOn::detached($unnamed);
    }

    private static function defaultBranchOf(string|Absent $configured, RunOn $run, Repository $repository): Scope
    {
        $candidates = [
            $configured instanceof Absent ? $configured : RunOn::branchNamed($configured),
            $run->defaultBranch(),
            $repository->defaultBranch(),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Scope) {
                return $candidate;
            }
        }

        return Scope::branch(self::MAIN);
    }
}
