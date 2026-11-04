<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use Closure;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\DefaultBranch;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Context;
use NightWorksIO\MutationGate\Core\Config\ContextChain;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\CiPlan;

/**
 * The contexts a run is in (ADR-0071), told from the environment and from what the CI plan the settings choose
 * says of the run: `local` outside CI; in CI, `ci`, with `pr` over it on a pull request or a merge group, and
 * `main` over it on the default branch. A run in CI the plan says nothing of is `ci` alone.
 *
 * @internal
 */
final readonly class RunContext
{
    public function __construct(private Variables $environment)
    {
    }

    /**
     * The contexts `--context` names, else those told from the settings every layer makes without its blocks,
     * which no block can change, as a block sets neither the CI plan nor the default branch.
     *
     * @param Closure(): (Settings|Invalid|CannotJudge) $base
     */
    public function chain(CommandLine $given, Closure $base, Extensions $extensions): ContextChain|Invalid|CannotJudge
    {
        $forced = $given->context();

        if (! $forced instanceof NotGiven) {
            return $forced instanceof Context ? $forced->chain() : $forced;
        }

        $settings = $base();
        $registry = $settings instanceof Settings
            ? new Chosen($extensions)->given($given, $settings->extensions())
            : $settings;

        return $registry instanceof Extensions ? $this->of($settings, $registry) : $registry;
    }

    /** The contexts of a run with these settings. */
    private function of(Settings $settings, Extensions $registry): ContextChain
    {
        if (! $this->environment->inCi()) {
            return Context::Local->chain();
        }

        $plan = new Chosen($registry)->ciPlan(ChosenPlan::of($settings, $registry, $this->environment));
        $run = $plan instanceof CiPlan ? $plan->runOn() : $plan;

        return $run instanceof RunOn ? $this->onRun($run, $settings)->chain() : Context::Ci->chain();
    }

    private function onRun(RunOn $run, Settings $settings): Context
    {
        $scope = $run->scope();
        $default = DefaultBranch::of($settings->ci()->defaultBranch(), $run->defaultBranch());

        return match (true) {
            $run->isProposed() => Context::PullRequest,
            $scope instanceof Scope && $scope->equals($default) => Context::Main,
            default => Context::Ci,
        };
    }
}
