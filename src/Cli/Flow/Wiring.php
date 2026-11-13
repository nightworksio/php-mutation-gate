<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;
use function function_exists;
use function ini_get;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\ReferencedFiles;
use NightWorksIO\MutationGate\Adapter\Filesystem\Resources;
use NightWorksIO\MutationGate\Adapter\Filesystem\Shipped;
use NightWorksIO\MutationGate\Adapter\Opcache\Prover;
use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStanOption;
use NightWorksIO\MutationGate\Adapter\Project\SuiteConfig;
use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Adapter\Serve\OutbreakDaemon;
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
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinAnalyser;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\BuiltinVersionControl;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
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

use function posix_geteuid;

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
            ? $chosen->runner(ToldRunner::of($settings, $checker, $mutators, $suite))
            : $suite;
        $found = $chosen->treeSource($settings->treeSource());
        $trees = $found instanceof TreeSource ? new DeclaredTrees($found, $settings->floors()->trees()) : $found;
        $costs = $chosen->costModel($settings->shards()->costs()->model());
        $ci = $chosen->ciPlan(ChosenPlan::of($settings, $this->extensions, $this->environment));
        $withheld = $chosen->withheld($settings->ci(), $settings->runner()->withhold(), $settings->reports());
        $source = $this->versionControl($settings)->over($this->sourceOptions($settings, $withheld));
        $changes = $chosen->changeSource($source);
        $repository = $chosen->repository($source);
        $store = new WiredStore($this->environment, $settings);
        $kept = $store->kept($store->configured($chosen, $ci, $repository), $project->root());
        $trusted = $store->trusted($changes, $kept);
        $proofs = $store->merged($trusted, $kept);
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
            ! $changes instanceof ChangeSource => $changes,
            ! $repository instanceof Repository => $repository,
            default => new Adapters(
                $runner,
                $suite,
                $checker,
                $this->identified($checker, $withheld, $project, $changes),
                $trees,
                $proofs,
                $costs,
                $ci,
                $trusted,
                WiredStore::repository($repository, $trusted),
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
                $this->outbreak($settings->shards()->outbreak(), $project->root()->value()),
            )->judgedAmong($settings->triage()->suites()),
        };
    }

    /**
     * The engine the settings ask for, with the state `outbreak serve` keeps
     * where this process can name its owner to the daemon's socket.
     */
    private function outbreak(OutbreakSettings $settings, string $root): Outbreak
    {
        $outbreak = Outbreak::configured($settings, $root, Resources::at(Shipped::Outbreak));

        return function_exists('posix_geteuid')
            ? $outbreak->servedBy(OutbreakDaemon::of($root, posix_geteuid(), $settings->timeout()))
            : $outbreak;
    }

    /**
     * Who the analyser is, its config's digest that of the configuration it
     * resolves and every file that references, where it can say them, and
     * that of its config file where it cannot (ADR-0020). A referenced file
     * under the root that version control ignores is none of them.
     */
    private function identified(
        StaticChecker|NoAnalyser $checker,
        Withheld $withheld,
        Directory $project,
        ChangeSource $changes,
    ): AnalyserIdentity|NoAnalyser|CannotJudge {
        if ($checker instanceof NoAnalyser) {
            return $checker;
        }

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
