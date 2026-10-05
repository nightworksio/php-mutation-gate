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

    private function __construct(
        private Choice|Absent $plan,
        private string|Absent $defaultBranch,
        private string|Absent $check,
        private bool|Absent $trustMergedPullRequests,
        private Path|Absent $gitlabTemplate,
        private BuildkiteStep|Absent $buildkiteStep,
        private Path|Absent $buildkiteDefinition,
        private Path|Absent $azureDefinition,
        private Path|Absent $bitbucketDefinition,
        private Path|Absent $jenkinsDefinition,
    ) {
    }

    public static function of(
        Choice|Absent $plan = new Absent(),
        string|Absent $defaultBranch = new Absent(),
        string|Absent $check = new Absent(),
        bool|Absent $trustMergedPullRequests = new Absent(),
        Path|Absent $gitlabTemplate = new Absent(),
        BuildkiteStep|Absent $buildkiteStep = new Absent(),
        Path|Absent $buildkiteDefinition = new Absent(),
        Path|Absent $azureDefinition = new Absent(),
        Path|Absent $bitbucketDefinition = new Absent(),
        Path|Absent $jenkinsDefinition = new Absent(),
    ): self {
        return new self(
            $plan,
            $defaultBranch,
            $check,
            $trustMergedPullRequests,
            $gitlabTemplate,
            $buildkiteStep,
            $buildkiteDefinition,
            $azureDefinition,
            $bitbucketDefinition,
            $jenkinsDefinition,
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
            trustMergedPullRequests: $none->trustsMergedPullRequests(),
            gitlabTemplate: $none->gitlabTemplate(),
            buildkiteStep: $none->buildkiteStep(),
            buildkiteDefinition: $none->buildkiteDefinition(),
            azureDefinition: $none->azureDefinition(),
            bitbucketDefinition: $none->bitbucketDefinition(),
            jenkinsDefinition: $none->jenkinsDefinition(),
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
     * `ci.trustMergedPullRequests`: whether the default branch takes a merged pull request's passing run as proof
     * of its tree, so that anyone who can push a branch of the repository can spare that tree a re-check (ADR-0005).
     */
    public function trustsMergedPullRequests(): bool
    {
        return $this->trustMergedPullRequests instanceof Absent || $this->trustMergedPullRequests;
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
     * count as its CI definition (ADR-0024 decision 3).
     */
    public function azureDefinition(): Path
    {
        return $this->azureDefinition instanceof Path ? $this->azureDefinition : Path::of(Definitions::AZURE);
    }

    /**
     * `ci.bitbucket.definition`: the pipeline file that runs the gate under Bitbucket Pipelines, which reach and
     * the proof key count as its CI definition (ADR-0024 decision 3).
     */
    public function bitbucketDefinition(): Path
    {
        return $this->bitbucketDefinition instanceof Path
            ? $this->bitbucketDefinition
            : Path::of(Definitions::BITBUCKET);
    }

    /**
     * `ci.jenkins.definition`: the Jenkinsfile that runs the gate under Jenkins, which reach and the proof key count
     * as its CI definition (ADR-0024 decision 3).
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
                Member::of('trustMergedPullRequests', $this->trustMergedPullRequests),
                Member::unlessEmpty(
                    'gitlab',
                    Json::object(Member::of('template', $this->path($origin, $this->gitlabTemplate))),
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
                        Member::of(CiJob::DEFINITION, $this->path($origin, $this->buildkiteDefinition)),
                    ),
                ),
                Member::unlessEmpty(
                    'azure',
                    Json::object(Member::of(CiJob::DEFINITION, $this->path($origin, $this->azureDefinition))),
                ),
                Member::unlessEmpty(
                    'bitbucket',
                    Json::object(Member::of(CiJob::DEFINITION, $this->path($origin, $this->bitbucketDefinition))),
                ),
                Member::unlessEmpty(
                    'jenkins',
                    Json::object(Member::of(CiJob::DEFINITION, $this->path($origin, $this->jenkinsDefinition))),
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
            ...$this->defaultBranch instanceof Absent
                ? []
                : [sprintf('Ci::defaultBranch(%s)', PhpCalls::literal($this->defaultBranch))],
            ...$this->check instanceof Absent ? [] : [sprintf('Ci::check(%s)', PhpCalls::literal($this->check))],
            ...$this->trustMergedPullRequests instanceof Absent ? [] : [
                $this->trustMergedPullRequests
                    ? 'Ci::trustingMergedPullRequests()'
                    : 'Ci::notTrustingMergedPullRequests()',
            ],
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

    private function path(PathOrigin $origin, Path|Absent $path): string|Absent
    {
        return $path instanceof Path ? $origin->written($path) : $path;
    }

}
