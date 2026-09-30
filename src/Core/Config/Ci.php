<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function implode;

use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/** The CI the plan is written for, and what it needs to know about it (ADR-0006, ADR-0007): `ci`. */
final readonly class Ci implements Part
{
    /** The check-run the verdict reports under. */
    private const string CHECK = 'mutation / verdict';

    private const string GITLAB_TEMPLATE = '.gitlab/mutation-gate.yml';

    private const string BUILDKITE_DEFINITION = '.buildkite/pipeline.yml';

    /** The CI plans the builder has a method of its own for. */
    private const array PLANS = [
        BuiltinCiPlan::GitHub->value,
        BuiltinCiPlan::GitLab->value,
        BuiltinCiPlan::Buildkite->value,
        BuiltinCiPlan::CircleCi->value,
        BuiltinCiPlan::Json->value,
    ];

    private function __construct(
        private Choice|Absent $plan,
        private string|Absent $defaultBranch,
        private string|Absent $check,
        private Path|Absent $gitlabTemplate,
        private BuildkiteStep|Absent $buildkiteStep,
        private Path|Absent $buildkiteDefinition,
    ) {
    }

    public static function of(
        Choice|Absent $plan = new Absent(),
        string|Absent $defaultBranch = new Absent(),
        string|Absent $check = new Absent(),
        Path|Absent $gitlabTemplate = new Absent(),
        BuildkiteStep|Absent $buildkiteStep = new Absent(),
        Path|Absent $buildkiteDefinition = new Absent(),
    ): self {
        return new self($plan, $defaultBranch, $check, $gitlabTemplate, $buildkiteStep, $buildkiteDefinition);
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
            gitlabTemplate: $none->gitlabTemplate(),
            buildkiteStep: $none->buildkiteStep(),
            buildkiteDefinition: $none->buildkiteDefinition(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->plan, $later->plan),
                Absent::laid($this->defaultBranch, $later->defaultBranch),
                Absent::laid($this->check, $later->check),
                Absent::laid($this->gitlabTemplate, $later->gitlabTemplate),
                match (true) {
                    $later->buildkiteStep instanceof Absent => $this->buildkiteStep,
                    $this->buildkiteStep instanceof Absent => $later->buildkiteStep,
                    default => $this->buildkiteStep->merged($later->buildkiteStep),
                },
                Absent::laid($this->buildkiteDefinition, $later->buildkiteDefinition),
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

    /** The file whose hidden `.mutation-gate` job GitLab's generated jobs extend. */
    public function gitlabTemplate(): Path
    {
        return $this->gitlabTemplate instanceof Path ? $this->gitlabTemplate : Path::of(self::GITLAB_TEMPLATE);
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

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'ci',
            Json::object(
                Member::of('plan', $this->plan instanceof Choice ? $this->plan->written() : $this->plan),
                Member::of('defaultBranch', $this->defaultBranch),
                Member::of('check', $this->check),
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
                        Member::of('definition', $this->path($origin, $this->buildkiteDefinition)),
                    ),
                ),
            ),
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->plan instanceof Choice ? [PhpCalls::chosen($this->plan, 'Ci', ...self::PLANS)] : [],
            ...$this->defaultBranch instanceof Absent
                ? []
                : [sprintf('Ci::defaultBranch(%s)', PhpCalls::literal($this->defaultBranch))],
            ...$this->check instanceof Absent ? [] : [sprintf('Ci::check(%s)', PhpCalls::literal($this->check))],
            ...$this->gitlabTemplate instanceof Path
                ? [sprintf('Ci::gitlabTemplate(%s)', PhpCalls::literal($origin->written($this->gitlabTemplate)))]
                : [],
            ...$this->buildkiteStep instanceof BuildkiteStep
                ? [sprintf('Ci::buildkiteStep(%s)', implode(', ', PhpOptions::of($this->buildkiteStep->json())))]
                : [],
            ...$this->buildkiteDefinition instanceof Path ? [sprintf(
                'Ci::buildkiteDefinition(%s)',
                PhpCalls::literal($origin->written($this->buildkiteDefinition)),
            )] : [],
        ]);
    }

    /**
     * The options the gate hands a CI plan it builds in, from this section: `gitlab` its template, and
     * `buildkite` its step and the pipeline that runs it; none for any other.
     */
    public function planOptions(Name $plan): Options
    {
        return Options::of(match ($plan->value()) {
            BuiltinCiPlan::GitLab->value => Json::object(Member::of('template', $this->gitlabTemplate()->value())),
            BuiltinCiPlan::Buildkite->value => Json::object(
                Member::of('step', $this->buildkiteStep()->json()),
                Member::of('definition', $this->buildkiteDefinition()->value()),
            ),
            default => Json::object(),
        });
    }

    private function path(PathOrigin $origin, Path|Absent $path): string|Absent
    {
        return $path instanceof Path ? $origin->written($path) : $path;
    }
}
