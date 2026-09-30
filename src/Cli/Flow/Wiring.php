<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_values;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\TreeSource;

/**
 * The adapters the settings choose, built from the registry: the runner, the
 * tree source and the proof store the config names; the learned cost model;
 * the CI plan the config names or the environment shows; the version
 * control source, GitHub's where GitHub Actions runs, and git's otherwise;
 * and what no process that runs the project's code may see: every run's
 * credentials, the CI's own tokens and `runner.withhold`.
 */
final readonly class Wiring
{
    /** Each CI the gate detects, by the variable its runners set to `true`. */
    private const array DETECTED = [
        'GITLAB_CI' => 'gitlab',
        'BUILDKITE' => 'buildkite',
        'CIRCLECI' => 'circleci',
    ];

    private const string PLAIN = 'json';

    private const string GIT = 'git';

    private const string GITHUB = 'github';

    private const string COSTS = 'learned';

    public function __construct(private Extensions $extensions, private Variables $environment)
    {
    }

    public function adapters(Settings $settings, Directory $project): Adapters|Invalid|CannotJudge
    {
        $chosen = new Chosen($this->extensions);
        $lookup = Lookup::in($this->extensions);
        $source = $this->environment->onGitHubActions() ? self::GITHUB : self::GIT;
        $runner = $chosen->runner($settings->runner()->choice());
        $trees = $chosen->treeSource($settings->treeSource());
        $proofs = $chosen->proofStore($settings->proofs()->store());
        $costs = $lookup->costModel(Name::of(self::COSTS), $this->costOptions($settings));
        $ci = $chosen->ciPlan($this->ciOf($settings));
        $withheld = $this->withheld($settings, $chosen, $ci);
        $changes = $lookup->changeSource(Name::of($source), $this->sourceOptions($settings, $withheld));
        $repository = $lookup->repository(Name::of($source), $this->sourceOptions($settings, $withheld));

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
     * What no process the gate starts may see, git's and the project's: every run's
     * credentials, `runner.withhold`, and the tokens of every CI the gate
     * knows, whichever plan renders the run, since the job may run on any.
     */
    private function withheld(Settings $settings, Chosen $chosen, CiPlan|Invalid|CannotJudge $ci): Withheld
    {
        $withheld = $ci instanceof CiPlan ? Withheld::standard()->and($ci->withheld()) : Withheld::standard();
        $withheld = $withheld->and($settings->runner()->withhold());

        foreach ([self::GITHUB, self::PLAIN, ...array_values(self::DETECTED)] as $plan) {
            $known = $chosen->ciPlan(Choice::of($plan, $this->ciOptions($plan, $settings)));
            $withheld = $known instanceof CiPlan ? $withheld->and($known->withheld()) : $withheld;
        }

        return $withheld;
    }

    /**
     * The CI plan the config names, or the one the environment shows, with
     * the options its `ci.*` settings give, under those a named plan gives
     * itself.
     */
    private function ciOf(Settings $settings): Choice
    {
        $named = $settings->ci()->plan();
        $plan = $named instanceof Choice ? $named->use() : $this->detected();
        $options = $this->ciOptions($plan, $settings);

        return Choice::of($plan, $named instanceof Choice ? $options->merged($named->options()) : $options);
    }

    /** The CI the environment shows: GitHub Actions, then the first other that sets its variable to `true`. */
    private function detected(): string
    {
        $detected = $this->environment->onGitHubActions() ? self::GITHUB : self::PLAIN;

        foreach (self::DETECTED as $variable => $plan) {
            $detected = $detected === self::PLAIN && $this->environment->valueOf($variable) === 'true'
                ? $plan
                : $detected;
        }

        return $detected;
    }

    /** The options a CI plan takes from the `ci.*` settings: GitLab's template, and Buildkite's step and pipeline. */
    private function ciOptions(string $plan, Settings $settings): Json
    {
        $ci = $settings->ci();

        return match ($plan) {
            'gitlab' => Json::object(Member::of('template', $ci->gitlabTemplate()->value())),
            'buildkite' => Json::object(
                Member::of('step', $ci->buildkiteStep()),
                Member::of('definition', $ci->buildkiteDefinition()->value()),
            ),
            default => Json::object(),
        };
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
        return Options::ofJson(Json::object(
            Member::of('check', $settings->ci()->check()),
            Member::of('withhold', Json::items(...$withheld)),
        )->line());
    }

    private function costOptions(Settings $settings): Options
    {
        return Options::ofJson(JsonText::compact(['secondsPerLine' => [...$settings->shards()->secondsPerLine()]]));
    }
}
