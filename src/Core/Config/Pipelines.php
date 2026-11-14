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

/**
 * The pipeline files each CI runs the gate from, which reach and the proof
 * key count as its CI definition, and what a generated job is built from:
 * GitLab's template, Buildkite's step and pipeline, and Azure's, Bitbucket's
 * and Jenkins' pipelines (ADR-0024): keys of `ci`.
 */
final readonly class Pipelines
{
    private const string BUILDKITE_DEFINITION = '.buildkite/pipeline.yml';

    private function __construct(
        private Path|Absent $gitlabTemplate,
        private BuildkiteStep|Absent $buildkiteStep,
        private Path|Absent $buildkiteDefinition,
        private Path|Absent $azureDefinition,
        private Path|Absent $bitbucketDefinition,
        private Path|Absent $jenkinsDefinition,
    ) {
    }

    public static function of(
        Path|Absent $gitlabTemplate = new Absent(),
        BuildkiteStep|Absent $buildkiteStep = new Absent(),
        Path|Absent $buildkiteDefinition = new Absent(),
        Path|Absent $azureDefinition = new Absent(),
        Path|Absent $bitbucketDefinition = new Absent(),
        Path|Absent $jenkinsDefinition = new Absent(),
    ): self {
        return new self(
            $gitlabTemplate,
            $buildkiteStep,
            $buildkiteDefinition,
            $azureDefinition,
            $bitbucketDefinition,
            $jenkinsDefinition,
        );
    }

    /** These files, with what a later layer sets laid over them, Buildkite's step merged key by key. */
    public function over(self $later): self
    {
        return new self(
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
        );
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

    /**
     * The options the gate hands a CI plan it builds in: `gitlab` its template, `buildkite` its step and the
     * pipeline that runs it, and `azure`, `bitbucket` and `jenkins` the pipeline that runs each; none for any
     * other.
     */
    public function options(Name $plan): Options
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

    /** The keys of `ci` a layer sets of these, as it writes them. */
    public function written(PathOrigin $origin): Json
    {
        return Json::object(
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
        );
    }

    /** The builder's `Pipeline` calls for what a layer sets of these. */
    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = [
            ...$this->gitlabTemplate instanceof Path
                ? [sprintf('Pipeline::gitlabTemplate(%s)', PhpCalls::literal($origin->written($this->gitlabTemplate)))]
                : [],
            ...$this->buildkiteStep instanceof BuildkiteStep
                ? [sprintf('Pipeline::buildkiteStep(%s)', implode(', ', PhpOptions::of($this->buildkiteStep->json())))]
                : [],
        ];
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

        return PhpCalls::inWith(...$calls);
    }
}
