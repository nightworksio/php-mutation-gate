<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function implode;

use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiTemplate;
use NightWorksIO\MutationGate\Core\Ci\Definitions;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/** The CI the plan is written for, and what it needs to know about it (ADR-0006, ADR-0007): `ci`. */
final readonly class Ci implements Part
{
    /** The check-run the verdict reports under. */
    private const string CHECK = 'mutation / verdict';

    private const string BUILDKITE_DEFINITION = '.buildkite/pipeline.yml';

    /** Monday, at three. */
    private const string WEEKLY = '0 3 * * 1';

    private function __construct(
        private Choice|Absent $plan,
        private string|Absent $defaultBranch,
        private string|Absent $check,
        private MergedTrust|Absent $trustMergedPullRequests,
        private OwnScopeTrust|Absent $mergedOwnScopeProofs,
        private Path|Absent $gitlabTemplate,
        private BuildkiteStep|Absent $buildkiteStep,
        private Path|Absent $buildkiteDefinition,
        private Path|Absent $azureDefinition,
        private Path|Absent $bitbucketDefinition,
        private Path|Absent $jenkinsDefinition,
        private string|Absent $schedule,
    ) {
    }

    public static function of(
        Choice|Absent $plan = new Absent(),
        string|Absent $defaultBranch = new Absent(),
        string|Absent $check = new Absent(),
        MergedTrust|Absent $trustMergedPullRequests = new Absent(),
        OwnScopeTrust|Absent $mergedOwnScopeProofs = new Absent(),
        Path|Absent $gitlabTemplate = new Absent(),
        BuildkiteStep|Absent $buildkiteStep = new Absent(),
        Path|Absent $buildkiteDefinition = new Absent(),
        Path|Absent $azureDefinition = new Absent(),
        Path|Absent $bitbucketDefinition = new Absent(),
        Path|Absent $jenkinsDefinition = new Absent(),
        string|Absent $schedule = new Absent(),
    ): self {
        return new self(
            $plan,
            $defaultBranch,
            $check,
            $trustMergedPullRequests,
            $mergedOwnScopeProofs,
            $gitlabTemplate,
            $buildkiteStep,
            $buildkiteDefinition,
            $azureDefinition,
            $bitbucketDefinition,
            $jenkinsDefinition,
            $schedule,
        );
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of(
            check: $none->check(),
            trustMergedPullRequests: $none->mergedTrust(),
            mergedOwnScopeProofs: $none->mergedOwnScopeProofs(),
            gitlabTemplate: $none->gitlabTemplate(),
            buildkiteStep: $none->buildkiteStep(),
            buildkiteDefinition: $none->buildkiteDefinition(),
            azureDefinition: $none->azureDefinition(),
            bitbucketDefinition: $none->bitbucketDefinition(),
            jenkinsDefinition: $none->jenkinsDefinition(),
            schedule: $none->schedule(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->plan, $later->plan),
                Absent::laid($this->defaultBranch, $later->defaultBranch),
                Absent::laid($this->check, $later->check),
                Absent::laid($this->trustMergedPullRequests, $later->trustMergedPullRequests),
                Absent::laid($this->mergedOwnScopeProofs, $later->mergedOwnScopeProofs),
                Absent::laid($this->gitlabTemplate, $later->gitlabTemplate),
                match (true) {
                    $later->buildkiteStep instanceof Absent => $this->buildkiteStep,
                    $this->buildkiteStep instanceof Absent => $later->buildkiteStep,
                    default => $this->buildkiteStep->merged($later->buildkiteStep),
                },
                Absent::laid($this->buildkiteDefinition, $later->buildkiteDefinition),
                Absent::laid($this->azureDefinition, $later->azureDefinition),
                Absent::laid($this->bitbucketDefinition, $later->bitbucketDefinition),
                Absent::laid($this->jenkinsDefinition, $later->jenkinsDefinition),
                Absent::laid($this->schedule, $later->schedule),
            )
            : $this;
    }

    /**
     * The CI plan, with the options this section gives a built-in one laid under its own, or none, when it is
     * detected from the environment.
     */
    public function plan(): Choice|Absent
    {
        return $this->plan instanceof Choice && $this->plan->use() instanceof Name
            ? Choice::of(
                $this->plan->use()->value(),
                $this->plan->options()->over($this->planOptions($this->plan->use())->written()),
            )
            : $this->plan;
    }

    /** The default branch, or none, when the CI or git says which it is. */
    public function defaultBranch(): string|Absent
    {
        return $this->defaultBranch;
    }

    /**
     * `ci.check`: the name of the check-run the verdict reports under, which a passing run's proof records and a
     * pull request's passing run is trusted by (ADR-0007).
     */
    public function check(): string
    {
        return $this->check instanceof Absent ? self::CHECK : $this->check;
    }

    /**
     * `ci.schedule`: the cron schedule `init --ci` writes for the full run,
     * which reaches the units no change does; weekly unless the config says.
     * A workflow's triggers are its own, so a reusable workflow cannot hold it.
     */
    public function schedule(): string
    {
        return $this->schedule instanceof Absent ? self::WEEKLY : $this->schedule;
    }

    /**
     * `ci.trustMergedPullRequests`: what the default branch takes from a merged pull request's pass, nothing unless
     * the config says. The pass is a record the pull request's own run computed, an untrusted write, so anything
     * but `false` trusts that run (ADR-0005, ADR-0139).
     */
    public function mergedTrust(): MergedTrust
    {
        return $this->trustMergedPullRequests instanceof MergedTrust
            ? $this->trustMergedPullRequests
            : MergedTrust::Off;
    }

    /** Whether the default branch takes anything from a merged pull request's pass. */
    public function trustsMergedPullRequests(): bool
    {
        return $this->mergedTrust()->trusts();
    }

    /**
     * `ci.mergedOwnScopeProofs`: what a merged pull request's pass that took results from its own scope's proofs is
     * trusted for, `reach` unless the config says (ADR-0139).
     */
    public function mergedOwnScopeProofs(): OwnScopeTrust
    {
        return $this->mergedOwnScopeProofs instanceof OwnScopeTrust
            ? $this->mergedOwnScopeProofs
            : OwnScopeTrust::Reach;
    }

    /** The file whose hidden `.mutation-gate` job GitLab's generated jobs extend. */
    public function gitlabTemplate(): Path
    {
        return $this->gitlabTemplate instanceof Path ? $this->gitlabTemplate : CiTemplate::gitlabTemplate();
    }

    /** `ci.buildkite.step`: the step keys every generated Buildkite step is built from. */
    public function buildkiteStep(): BuildkiteStep
    {
        return $this->buildkiteStep instanceof BuildkiteStep ? $this->buildkiteStep : BuildkiteStep::none();
    }

    /**
     * `ci.buildkite.definition`: the pipeline file that runs the gate under Buildkite, which reach and the proof
     * key count as its CI definition, since no Buildkite variable names the file a pipeline was uploaded from.
     */
    public function buildkiteDefinition(): Path
    {
        return $this->buildkiteDefinition instanceof Path
            ? $this->buildkiteDefinition
            : Path::of(self::BUILDKITE_DEFINITION);
    }

    /**
     * `ci.azure.definition`: the pipeline file that runs the gate under Azure DevOps, which reach and the proof key
     * count as its CI definition (ADR-0024).
     */
    public function azureDefinition(): Path
    {
        return $this->azureDefinition instanceof Path ? $this->azureDefinition : Path::of(Definitions::AZURE);
    }

    /**
     * `ci.bitbucket.definition`: the pipeline file that runs the gate under Bitbucket Pipelines, which reach and
     * the proof key count as its CI definition (ADR-0024).
     */
    public function bitbucketDefinition(): Path
    {
        return $this->bitbucketDefinition instanceof Path
            ? $this->bitbucketDefinition
            : Path::of(Definitions::BITBUCKET);
    }

    /**
     * `ci.jenkins.definition`: the Jenkinsfile that runs the gate under Jenkins, which reach and the proof key count
     * as its CI definition (ADR-0024).
     */
    public function jenkinsDefinition(): Path
    {
        return $this->jenkinsDefinition instanceof Path ? $this->jenkinsDefinition : Path::of(Definitions::JENKINS);
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'ci',
            Json::object(
                Member::of('plan', $this->plan instanceof Choice ? $this->plan->written() : $this->plan),
                Member::of('defaultBranch', $this->defaultBranch),
                Member::of('check', $this->check),
                Member::of('schedule', $this->schedule),
                Member::of(
                    'trustMergedPullRequests',
                    $this->trustMergedPullRequests instanceof MergedTrust
                        ? $this->trustMergedPullRequests->json()
                        : $this->trustMergedPullRequests,
                ),
                Member::of(
                    'mergedOwnScopeProofs',
                    $this->mergedOwnScopeProofs instanceof OwnScopeTrust
                        ? $this->mergedOwnScopeProofs->value
                        : $this->mergedOwnScopeProofs,
                ),
                Member::unlessEmpty(
                    'gitlab',
                    Json::object(Member::of('template', CiCalls::path($origin, $this->gitlabTemplate))),
                ),
                Member::unlessEmpty(
                    'buildkite',
                    Json::object(
                        Member::of(
                            'step',
                            $this->buildkiteStep instanceof BuildkiteStep
                                ? $this->buildkiteStep->json()
                                : $this->buildkiteStep,
                        ),
                        Member::of(CiJob::DEFINITION, CiCalls::path($origin, $this->buildkiteDefinition)),
                    ),
                ),
                Member::unlessEmpty(
                    'azure',
                    Json::object(Member::of(CiJob::DEFINITION, CiCalls::path($origin, $this->azureDefinition))),
                ),
                Member::unlessEmpty(
                    'bitbucket',
                    Json::object(Member::of(CiJob::DEFINITION, CiCalls::path($origin, $this->bitbucketDefinition))),
                ),
                Member::unlessEmpty(
                    'jenkins',
                    Json::object(Member::of(CiJob::DEFINITION, CiCalls::path($origin, $this->jenkinsDefinition))),
                ),
            ),
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->plan instanceof Choice
                ? [PhpCalls::chosen($this->plan, AdapterBuilder::Ci, ...BuiltinCiPlan::names())]
                : [],
            ...CiCalls::named('Ci::defaultBranch(%s)', $this->defaultBranch),
            ...CiCalls::named('Ci::check(%s)', $this->check),
            ...CiCalls::named('Ci::schedule(%s)', $this->schedule),
            ...CiCalls::trust($this->trustMergedPullRequests),
            ...CiCalls::ownScope($this->mergedOwnScopeProofs),
            ...$this->gitlabTemplate instanceof Path
                ? [sprintf('Pipeline::gitlabTemplate(%s)', PhpCalls::literal($origin->written($this->gitlabTemplate)))]
                : [],
            ...$this->buildkiteStep instanceof BuildkiteStep
                ? [sprintf('Pipeline::buildkiteStep(%s)', implode(', ', PhpOptions::of($this->buildkiteStep->json())))]
                : [],
            ...$this->definitionCalls($origin),
        ]);
    }

    /**
     * The options the gate hands a CI plan it builds in, from this section: `gitlab` its template, `buildkite`
     * its step and the pipeline that runs it, and `azure`, `bitbucket` and `jenkins` the pipeline that runs each;
     * none for any other.
     */
    public function planOptions(Name $plan): Options
    {
        return Options::of(match ($plan->value()) {
            BuiltinCiPlan::GitLab->value => Json::object(Member::of('template', $this->gitlabTemplate()->value())),
            BuiltinCiPlan::Buildkite->value => Json::object(
                Member::of('step', $this->buildkiteStep()->json()),
                Member::of(CiJob::DEFINITION, $this->buildkiteDefinition()->value()),
            ),
            BuiltinCiPlan::Azure->value => Json::object(
                Member::of(CiJob::DEFINITION, $this->azureDefinition()->value()),
            ),
            BuiltinCiPlan::Bitbucket->value => Json::object(
                Member::of(CiJob::DEFINITION, $this->bitbucketDefinition()->value()),
            ),
            BuiltinCiPlan::Jenkins->value => Json::object(
                Member::of(CiJob::DEFINITION, $this->jenkinsDefinition()->value()),
            ),
            default => Json::object(),
        });
    }

    /**
     * A `Pipeline` call for each CI's pipeline file this section names, by the method that names it.
     *
     * @return list<string>
     */
    private function definitionCalls(PathOrigin $origin): array
    {
        $calls = [];
        $named = [
            'buildkiteDefinition' => $this->buildkiteDefinition,
            'azureDefinition' => $this->azureDefinition,
            'bitbucketDefinition' => $this->bitbucketDefinition,
            'jenkinsDefinition' => $this->jenkinsDefinition,
        ];

        foreach ($named as $method => $path) {
            $calls = $path instanceof Path
                ? [...$calls, sprintf('Pipeline::%s(%s)', $method, PhpCalls::literal($origin->written($path)))]
                : $calls;
        }

        return $calls;
    }

}
