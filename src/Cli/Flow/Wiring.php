<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\LocalLedgers;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Config\DeclaredTrees;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinCostModel;
use NightWorksIO\MutationGate\Core\Config\BuiltinVersionControl;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\TreeSource;

/**
 * The adapters the settings choose, built from the registry: the runner, the
 * tree source and the proof store the config names, which outside CI is read
 * beneath the local ledgers and never written; the learned cost model;
 * the CI plan the config names or the environment shows; the version
 * control source, GitHub's where GitHub Actions runs, and git's otherwise;
 * and what no process that runs the project's code may see: every run's
 * credentials, the tokens of every CI plan, since the job may run on any,
 * and `runner.withhold`.
 */
final readonly class Wiring
{
    public function __construct(private Extensions $extensions, private Variables $environment)
    {
    }

    public function adapters(Settings $settings, Directory $project): Adapters|Invalid|CannotJudge
    {
        $chosen = new Chosen($this->extensions);
        $lookup = Lookup::in($this->extensions);
        $source = BuiltinVersionControl::in($this->environment)->named();
        $runner = $chosen->runner($settings->runner()->choice());
        $found = $chosen->treeSource($settings->treeSource());
        $trees = $found instanceof TreeSource ? new DeclaredTrees($found, $settings->floors()->trees()) : $found;
        $proofs = $this->kept($settings, $chosen->proofStore($settings->proofs()->store()));
        $costs = $lookup->costModel(BuiltinCostModel::Learned->named(), $settings->shards()->costOptions());
        $ci = $chosen->ciPlan($this->ciOf($settings));
        $withheld = $chosen->withheld($settings->ci(), $settings->runner()->withhold());
        $changes = $lookup->changeSource($source, $this->sourceOptions($settings, $withheld));
        $repository = $lookup->repository($source, $this->sourceOptions($settings, $withheld));

        return match (true) {
            ! $runner instanceof Runner => $runner,
            ! $trees instanceof TreeSource => $trees,
            ! $proofs instanceof ProofStore => $proofs,
            ! $costs instanceof CostModel => $costs,
            ! $ci instanceof CiPlan => $ci,
            ! $changes instanceof ChangeSource => $changes,
            ! $repository instanceof Repository => $repository,
            default => new Adapters(
                $runner,
                $trees,
                $proofs,
                $costs,
                $ci,
                $this->trustedChanges($changes, $proofs),
                $this->trustedRepository($repository, $proofs),
                $project,
                $this->environment,
                $withheld,
            ),
        };
    }

    /**
     * Where a run keeps its proofs (ADR-0010, decision 4): in CI, and in a
     * directory store the config names, there; elsewhere, in the local
     * ledgers over the store the config names.
     */
    private function kept(
        Settings $settings,
        ProofStore|Invalid|CannotJudge $configured,
    ): ProofStore|Invalid|CannotJudge {
        $local = ! $this->environment->inCi() && ! $settings->proofs()->keptOnDisk();

        return $configured instanceof ProofStore && $local ? LocalLedgers::over($configured) : $configured;
    }

    /**
     * The CI plan the config names, with the options its `ci.*` settings give laid under its own, or the one the
     * environment shows, with those options.
     */
    private function ciOf(Settings $settings): Choice
    {
        $named = $settings->ci()->plan();
        $detected = BuiltinCiPlan::detected($this->environment);

        return $named instanceof Choice
            ? $named
            : Choice::of($detected->value, $settings->ci()->planOptions($detected->named()));
    }

    /** GitHub's change source, reading each pull request's own ledger from the proof store; any other as it is. */
    private function trustedChanges(ChangeSource $source, ProofStore $proofs): ChangeSource
    {
        return $source instanceof PassedPullRequests ? $source->trusting($proofs) : $source;
    }

    /** GitHub's repository, reading each pull request's own ledger from the proof store; any other as it is. */
    private function trustedRepository(Repository $source, ProofStore $proofs): Repository
    {
        return $source instanceof PassedPullRequests ? $source->trusting($proofs) : $source;
    }

    /**
     * The version control source's options: the check-run the verdict reports
     * under, which GitHub's verifies, and what git's own children may not see.
     */
    private function sourceOptions(Settings $settings, Withheld $withheld): Options
    {
        return Options::of(Json::object(
            Member::of('check', $settings->ci()->check()),
            Member::of('withhold', Json::items(...$withheld)),
        ));
    }
}
