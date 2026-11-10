<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;
use function ini_get;

use NightWorksIO\MutationGate\Adapter\Azure\ContainerLedger;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\FetchedCopies;
use NightWorksIO\MutationGate\Adapter\Filesystem\LocalLedgers;
use NightWorksIO\MutationGate\Adapter\Filesystem\ReferencedFiles;
use NightWorksIO\MutationGate\Adapter\Filesystem\Resources;
use NightWorksIO\MutationGate\Adapter\Filesystem\Shipped;
use NightWorksIO\MutationGate\Adapter\GitHub\MergedLedgers;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Adapter\Infection\Setup;
use NightWorksIO\MutationGate\Adapter\Infection\StaticAnalysis;
use NightWorksIO\MutationGate\Adapter\Native\NativeOptions;
use NightWorksIO\MutationGate\Adapter\Opcache\Prover;
use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Adapter\Pest\PestOptions;
use NightWorksIO\MutationGate\Adapter\Project\SuiteConfig;
use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Adapter\Zstd\ZstdHere;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Config\ChosenPlan;
use NightWorksIO\MutationGate\Cli\Config\DeclaredTrees;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Registry\EnabledMutators;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\DefaultBranch;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BoundsOptions;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\BuiltinVersionControl;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
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
 *
 * @internal
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
        ToldChildren::by($settings);
        $lookup = Lookup::in($this->extensions);
        $runner = BuiltinRunner::tryFrom($settings->runner()->choice()->use()->value());
        $mutators = EnabledMutators::in($lookup, $settings->mutators(), $runner ?? NotGiven::value());

        return $mutators instanceof EnabledMutators ? $this->built($settings, $project, $mutators) : $mutators;
    }

    private function built(
        Settings $settings,
        Directory $project,
        EnabledMutators $mutators,
    ): Adapters|Invalid|CannotJudge {
        $chosen = new Chosen($this->extensions);
        $checker = $this->checker($settings->staticCheck(), $settings->toggles(), $chosen);
        $suite = Suite::configured($project, SuiteConfig::ofRunner($settings->runner()->choice()->use()->value()));
        $runner = $suite instanceof TestSuite
            ? $chosen->runner($this->runnerChoice($settings, $checker, $mutators, $suite))
            : $suite;
        $found = $chosen->treeSource($settings->treeSource());
        $trees = $found instanceof TreeSource ? new DeclaredTrees($found, $settings->floors()->trees()) : $found;
        $costs = $chosen->costModel($settings->shards()->costs()->model());
        $ci = $chosen->ciPlan(ChosenPlan::of($settings, $this->extensions, $this->environment));
        $withheld = $chosen->withheld($settings->ci(), $settings->runner()->withhold(), $settings->reports());
        $source = $this->versionControl($settings)->over($this->sourceOptions($settings, $withheld));
        $changes = $chosen->changeSource($source);
        $repository = $chosen->repository($source);
        $kept = $this->kept($settings, $this->configured($settings, $chosen, $ci, $repository), $project->root());
        $trusted = $changes instanceof ChangeSource && $kept instanceof ProofStore
            ? $this->trusted($changes, $kept, $settings)
            : $changes;
        $proofs = $trusted instanceof PassedPullRequests && $kept instanceof ProofStore
            ? MergedLedgers::over($kept, $trusted)
            : $kept;
        $processes = $settings->runner()->processes(Cores::counted());

        return match (true) {
            ! $suite instanceof TestSuite => $suite,
            ! $runner instanceof Runner => $runner,
            $checker instanceof Invalid, $checker instanceof CannotJudge => $checker,
            ! $trees instanceof TreeSource => $trees,
            ! $proofs instanceof ProofStore => $proofs,
            ! $costs instanceof CostModel => $costs,
            ! $ci instanceof CiPlan => $ci,
            ! $trusted instanceof ChangeSource => $trusted,
            ! $repository instanceof Repository => $repository,
            default => new Adapters(
                $runner,
                $suite,
                $checker,
                $checker instanceof StaticChecker
                    ? $this->identified($checker, $withheld, $project, $changes)
                    : $checker,
                $trees,
                $proofs,
                $costs,
                $ci,
                $trusted,
                $repository instanceof PassedPullRequests && $trusted instanceof PassedPullRequests
                    ? $trusted
                    : $repository,
                $project,
                $this->environment,
                $withheld,
                $processes,
                $this->counting($mutators->forTheEngine()),
                $mutators->besideTheRunners(),
                $mutators->skipped(),
                $mutators->security(),
                Narrowing::none(),
                Prover::of(
                    PHP_BINARY,
                    $project->root()->at(Workspace::equivalence()),
                    $processes,
                    ini_get('disable_functions'),
                    $settings->staticCheck()->opcacheBatch(),
                    $settings->staticCheck()->opcacheLimit()->seconds(),
                ),
                ConfigReads::none(),
                Outbreak::configured(
                    $settings->shards()->outbreak(),
                    $project->root()->value(),
                    Resources::at(Shipped::Outbreak),
                ),
            )->judgedAmong($settings->triage()->suites()),
        };
    }

    /**
     * Who the analyser is, its config's digest that of the configuration it
     * resolves and every file that references, where it can say them, and
     * that of its config file where it cannot (ADR-0020). A referenced file
     * under the root that version control ignores is none of them.
     */
    private function identified(
        StaticChecker $checker,
        Withheld $withheld,
        Directory $project,
        ChangeSource $changes,
    ): AnalyserIdentity|CannotJudge {
        $identity = $checker->identity($withheld);
        $settings = $checker->configuration($withheld);

        return $identity instanceof AnalyserIdentity && $settings instanceof AnalyserSettings
            ? $identity->configuredBy($settings->digest(
                ReferencedFiles::under($project->root())
                    ->listing($changes->listed(ReferencedFiles::inside($settings->references())))
                    ->digests($settings->references()),
            ))
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
     * The runner the config chooses: each told the bounds of each mutant's
     * limit, `timeouts.seconds` and `timeouts.most`, and the mutators whose
     * silence limit has a lower floor, `timeouts.tighter` (ADR-0008);
     * Infection told that the gate checks its survivors, where an analyser
     * does, so it runs no static analysis of its own (ADR-0020); and each
     * told the classes of the registered mutators it makes mutants with
     * (ADR-0021): Pest and Infection those the config turns on, beside
     * their own, and the PHPUnit runner the `default` set's and those
     * (ADR-0023); and each told the directories the tests are in, as the
     * PHPUnit config declares them, so a test outside the conventional
     * `tests` is one the runner lists.
     */
    private function runnerChoice(
        Settings $settings,
        StaticChecker|NoAnalyser|Invalid|CannotJudge $checker,
        EnabledMutators $mutators,
        TestSuite $suite,
    ): Choice {
        $runner = $settings->runner()->choice();
        $use = $runner->use()->value();
        $bounds = BoundsOptions::of($settings->triage());
        $beside = Json::items(...$mutators->besideTheRunners()->classes());
        $tests = $this->testsOf($suite);
        $infection = Json::object(...[
            Member::of(Setup::TESTS, $tests),
            ...$bounds,
            Member::of(Setup::MUTATORS, $beside),
            ...$checker instanceof StaticChecker
                ? [Member::of(Setup::STATIC_ANALYSIS, StaticAnalysis::Gate->value)]
                : [],
        ]);
        $native = Json::object(...[
            Member::of(NativeOptions::TESTS, $tests),
            ...$bounds,
            Member::of(NativeOptions::MUTATORS, Json::items(...$mutators->forTheEngine()->classes())),
        ]);

        return match (true) {
            $use === BuiltinRunner::Infection->value => Choice::of($use, $runner->options()->over($infection)),
            $use === BuiltinRunner::PhpUnit->value,
            $use === BuiltinRunner::PhpSpec->value,
            $use === BuiltinRunner::Codeception->value,
            $use === BuiltinRunner::Testo->value => Choice::of($use, $runner->options()->over($native)),
            $use === BuiltinRunner::Pest->value => Choice::of(
                $use,
                $runner->options()->over(Json::object(...[
                    Member::of(PestOptions::TESTS, $tests),
                    ...$bounds,
                    Member::of(PestOptions::MUTATORS, $beside),
                ])),
            ),
            default => $runner,
        };
    }

    /** The directories a suite's tests are in, as a runner's `tests` option holds them. */
    private function testsOf(TestSuite $suite): Json
    {
        $tests = [];

        foreach ($suite->paths() as $path) {
            $tests[] = $path->value();
        }

        return Json::items(...$tests);
    }


    /**
     * The static analyser `staticCheck.tool` names, reading `staticCheck.config`
     * where the config names one (ADR-0020): the one zero-config
     * finds for `auto`, and none for `none`.
     */
    private function checker(
        StaticCheck $static,
        Toggles $toggles,
        Chosen $chosen,
    ): StaticChecker|NoAnalyser|Invalid|CannotJudge {
        $tool = $static->tool();
        $use = $tool->use();
        $named = $use instanceof Name && $use->value() === StaticCheck::AUTO ? $this->detected->staticChecker() : $use;
        $config = $static->config();
        $options = $config instanceof Path ? $tool->options()->overPath(Key::of('config'), $config) : $tool->options();
        $options = $named instanceof Name && $named->value() === BuiltinAnalyser::PhpStan->value
            ? $options->over(Json::object(
                Member::of(PhpStanOption::Php->value, $static->phpIni()),
                Member::of(PhpStanOption::Turbo->value, $toggles->on(Toggle::StaticCheckTurbo)),
            ))
            : $options;

        return $named instanceof Name && $named->value() === StaticCheck::NONE
            ? NoAnalyser::configured()
            : $chosen->staticChecker(Choice::of($named->value(), $options));
    }

    /**
     * The store the config names; opened read-only, the default branch's scope alone, which is all a public URL
     * serves (ADR-0013), whether or not the CI and the repository can name that branch; an Azure
     * container told which scope is the default branch's, which its public container keeps (ADR-0028).
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
     * Where a run keeps its proofs (ADR-0010): in CI, and in a
     * directory store the config names, there; elsewhere, in the local
     * ledgers over the store the config names; read beside the copy `fetch`
     * wrote, as `proofs.copies` says; each ledger written as `proofs.compression` says, and read as it was written
     * (ADR-0085).
     */
    private function kept(
        Settings $settings,
        ProofStore|Invalid|CannotJudge $configured,
        Root $project,
    ): ProofStore|Invalid|CannotJudge {
        if (! $configured instanceof ProofStore) {
            return $configured;
        }

        $proofs = $settings->proofs();
        $local = ! $this->environment->inCi() && ! $proofs->keptOnDisk();
        $kept = $local ? LocalLedgers::over($configured, $project) : $configured;

        return FetchedCopies::over($kept, $proofs->copies(), $project)
            ->encoding(LedgerCodec::of($proofs->compression(), $proofs->compressionLevel(), ZstdHere::found()))
            ->limitedTo(LedgerLimits::kept($proofs->retained()));
    }

    /**
     * GitHub's change source, reading each pull request's own ledger from the proof store as
     * `ci.trustMergedPullRequests` and `ci.mergedOwnScopeProofs` allow; any other as it is.
     */
    private function trusted(ChangeSource $source, ProofStore $proofs, Settings $settings): ChangeSource
    {
        $ci = $settings->ci();

        return $source instanceof PassedPullRequests && $ci->trustsMergedPullRequests()
            ? $source->trusting($proofs, $ci->mergedTrust(), $ci->mergedOwnScopeProofs())
            : $source;
    }

    /** `versionControl`, or where no layer chooses one, the one the job's CI calls for. */
    private function versionControl(Settings $settings): Choice
    {
        $chosen = $settings->reach()->versionControl();

        return $chosen instanceof Choice
            ? $chosen
            : Choice::of(BuiltinVersionControl::in($this->environment)->value, Options::none());
    }

    /**
     * The version control source's options: the check-run the verdict reports
     * under, which GitHub's verifies, and what git's own children may not see.
     */
    private function sourceOptions(Settings $settings, Withheld $withheld): Json
    {
        return Json::object(
            Member::of('check', $settings->ci()->check()),
            Member::of('provingRange', $settings->shards()->planning()->provingRange()->farthest()),
            Member::of('withhold', Json::items(...$withheld)),
        );
    }
}
