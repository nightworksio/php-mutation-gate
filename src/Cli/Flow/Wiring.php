<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Adapter\Azure\ContainerLedger;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\LocalLedgers;
use NightWorksIO\MutationGate\Adapter\Filesystem\ReferencedFiles;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Adapter\Infection\Setup;
use NightWorksIO\MutationGate\Adapter\Infection\StaticAnalysis;
use NightWorksIO\MutationGate\Adapter\Opcache\Prover;
use NightWorksIO\MutationGate\Adapter\Pest\PestOptions;
use NightWorksIO\MutationGate\Adapter\PhpUnit\PhpUnitOptions;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Config\DeclaredTrees;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Registry\EnabledMutators;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\CiEnvironment;
use NightWorksIO\MutationGate\Core\Ci\DefaultBranch;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinCostModel;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\BuiltinVersionControl;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\StaticChecker;
use NightWorksIO\MutationGate\Port\TreeSource;

use const PHP_BINARY;

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
    public function __construct(
        private Extensions $extensions,
        private Variables $environment,
        private Detected $detected,
    ) {
    }

    public function adapters(Settings $settings, Directory $project): Adapters|Invalid|CannotJudge
    {
        $lookup = Lookup::in($this->extensions);
        $runner = BuiltinRunner::tryFrom($settings->runner()->choice()->use()->value());
        $mutators = EnabledMutators::in($lookup, $settings->mutators(), $runner ?? NotGiven::value());

        return $mutators instanceof EnabledMutators ? $this->built($settings, $project, $lookup, $mutators) : $mutators;
    }

    private function built(
        Settings $settings,
        Directory $project,
        Lookup $lookup,
        EnabledMutators $mutators,
    ): Adapters|Invalid|CannotJudge {
        $chosen = new Chosen($this->extensions);
        $source = BuiltinVersionControl::in($this->environment)->named();
        $checker = $this->checker($settings->staticCheck(), $chosen);
        $runner = $chosen->runner($this->runnerChoice($settings, $checker, $mutators));
        $found = $chosen->treeSource($settings->treeSource());
        $trees = $found instanceof TreeSource ? new DeclaredTrees($found, $settings->floors()->trees()) : $found;
        $costs = $lookup->costModel(BuiltinCostModel::Learned->named(), $settings->shards()->costOptions());
        $ci = $chosen->ciPlan($this->ciOf($settings));
        $withheld = $chosen->withheld($settings->ci(), $settings->runner()->withhold());
        $changes = $lookup->changeSource($source, $this->sourceOptions($settings, $withheld));
        $repository = $lookup->repository($source, $this->sourceOptions($settings, $withheld));
        $proofs = $this->kept($settings, $this->configured($settings, $chosen, $ci, $repository));

        return match (true) {
            ! $runner instanceof Runner => $runner,
            $checker instanceof Invalid, $checker instanceof CannotJudge => $checker,
            ! $trees instanceof TreeSource => $trees,
            ! $proofs instanceof ProofStore => $proofs,
            ! $costs instanceof CostModel => $costs,
            ! $ci instanceof CiPlan => $ci,
            ! $changes instanceof ChangeSource => $changes,
            ! $repository instanceof Repository => $repository,
            default => new Adapters(
                $runner,
                $checker,
                $checker instanceof StaticChecker ? $this->identified($checker, $withheld, $project) : $checker,
                $trees,
                $proofs,
                $costs,
                $ci,
                $this->trustedChanges($changes, $proofs),
                $this->trustedRepository($repository, $proofs),
                $project,
                $this->environment,
                $withheld,
                Cores::counted(),
                $this->counting($mutators->forTheEngine()),
                $mutators->besideTheRunners(),
                $mutators->skipped(),
                $mutators->security(),
                Mutators::all(),
                Prover::of(PHP_BINARY, $project->root()->at(Workspace::equivalence()), Cores::counted()),
            ),
        };
    }

    /**
     * Who the analyser is, its config's digest that of the configuration it
     * resolves and every file that references, where it can say them, and
     * that of its config file where it cannot (ADR-0020, decision 14).
     */
    private function identified(
        StaticChecker $checker,
        Withheld $withheld,
        Directory $project,
    ): AnalyserIdentity|CannotJudge {
        $identity = $checker->identity($withheld);
        $settings = $checker->configuration($withheld);

        return $identity instanceof AnalyserIdentity && $settings instanceof AnalyserSettings
            ? $identity->configuredBy(
                $settings->digest(ReferencedFiles::under($project->root())->digests($settings->references())),
            )
            : $identity;
    }

    /**
     * The engine that counts a plan's mutants, with the `default` set's mutators and those the config turns on,
     * where there are any.
     */
    private function counting(Enabled $mutators): Engine|NotGiven
    {
        return count($mutators) > 0 ? $mutators->engine() : NotGiven::value();
    }

    /**
     * The runner the config chooses: Infection told each mutant's cap,
     * `timeouts.seconds` (ADR-0008, decision 2), and that the gate checks its
     * survivors, where an analyser does, so it runs no static analysis of its
     * own (ADR-0020, decision 13); the PHPUnit runner told the cap on each
     * mutant's limit, `timeouts.seconds`; and each told the classes of the
     * registered mutators it makes mutants with (ADR-0021): Pest and
     * Infection those the config turns on, beside their own, and the PHPUnit
     * runner the `default` set's and those (ADR-0023, decision 8).
     */
    private function runnerChoice(
        Settings $settings,
        StaticChecker|NoAnalyser|Invalid|CannotJudge $checker,
        EnabledMutators $mutators,
    ): Choice {
        $runner = $settings->runner()->choice();
        $use = $runner->use()->value();
        $seconds = $settings->triage()->limit()->seconds();
        $beside = Json::items(...$mutators->besideTheRunners()->classes());
        $infection = Json::object(
            Member::of(Setup::TIMEOUT, $seconds),
            Member::of(Setup::MUTATORS, $beside),
            ...$checker instanceof StaticChecker
                ? [Member::of(Setup::STATIC_ANALYSIS, StaticAnalysis::Gate->value)]
                : [],
        );
        $native = Json::object(
            Member::of(PhpUnitOptions::TIMEOUT, $seconds),
            Member::of(PhpUnitOptions::MUTATORS, Json::items(...$mutators->forTheEngine()->classes())),
        );

        return match (true) {
            $use === BuiltinRunner::Infection->value => Choice::of($use, $runner->options()->over($infection)),
            $use === BuiltinRunner::PhpUnit->value => Choice::of($use, $runner->options()->over($native)),
            $use === BuiltinRunner::Pest->value => Choice::of(
                $use,
                $runner->options()->over(Json::object(Member::of(PestOptions::MUTATORS, $beside))),
            ),
            default => $runner,
        };
    }


    /**
     * The static analyser `staticCheck.tool` names, reading `staticCheck.config`
     * where the config names one (ADR-0020, decision 8): the one zero-config
     * finds for `auto`, and none for `none`.
     */
    private function checker(StaticCheck $static, Chosen $chosen): StaticChecker|NoAnalyser|Invalid|CannotJudge
    {
        $tool = $static->tool();
        $use = $tool->use();
        $named = $use instanceof Name && $use->value() === StaticCheck::AUTO ? $this->detected->staticChecker() : $use;
        $config = $static->config();
        $options = $config instanceof Path ? $tool->options()->overPath(Key::of('config'), $config) : $tool->options();

        return $named instanceof Name && $named->value() === StaticCheck::NONE
            ? NoAnalyser::configured()
            : $chosen->staticChecker(Choice::of($named->value(), $options));
    }

    /**
     * The store the config names; opened read-only, the default branch's scope alone, which is all a public URL
     * serves (ADR-0013 decision 13), whether or not the CI and the repository can name that branch; an Azure
     * container told which scope is the default branch's, which its public container keeps (ADR-0028 decision 4).
     */
    private function configured(
        Settings $settings,
        Chosen $chosen,
        CiPlan|Invalid|CannotJudge $ci,
        Repository|Invalid|CannotJudge $repository,
    ): ProofStore|Invalid|CannotJudge {
        $store = $chosen->proofStore($settings->proofs()->store());
        $run = $ci instanceof CiPlan ? $ci->runOn() : $ci;
        $detected = [
            ...$run instanceof RunOn ? [$run->defaultBranch()] : [],
            ...$repository instanceof Repository ? [$repository->defaultBranch()] : [],
        ];

        $default = static fn(): Scope => DefaultBranch::of($settings->ci()->defaultBranch(), ...$detected);

        return match (true) {
            $store instanceof PublicLedger => $store->onlyReading($default()),
            $store instanceof ContainerLedger => $store->forDefaultBranch($default()),
            default => $store,
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
     * The CI plan the config names, with the options its `ci.*` settings give laid under its own, or the one a
     * registered plan detects the job runs in, with those options; the JSON plan where none does.
     */
    private function ciOf(Settings $settings): Choice
    {
        $named = $settings->ci()->plan();
        $found = $this->extensions->detectedCiPlan(CiEnvironment::of($this->environment)->shows(...));
        $detected = $found instanceof Name ? $found : BuiltinCiPlan::Json->named();

        return $named instanceof Choice
            ? $named
            : Choice::of($detected->value(), $settings->ci()->planOptions($detected));
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
