<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function implode;

use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Location;
use NightWorksIO\MutationGate\Core\Config\Definition\OpenObject;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/** The CI the plan is written for, and what it needs to know about it (ADR-0006, ADR-0007): `ci`. */
final readonly class Ci implements Part
{
    /** The check-run the verdict reports under. */
    private const string CHECK = 'mutation / verdict';

    private const string GITLAB_TEMPLATE = '.gitlab/mutation-gate.yml';

    private const string BUILDKITE_DEFINITION = '.buildkite/pipeline.yml';

    /** The CI plans the builder has a method of its own for. */
    private const array PLANS = ['github', 'gitlab', 'buildkite', 'circleci', 'json'];

    private function __construct(
        private Choice|Absent $plan,
        private string|Absent $defaultBranch,
        private string|Absent $check,
        private Path|Absent $gitlabTemplate,
        private Json|Absent $buildkiteStep,
        private Path|Absent $buildkiteDefinition,
    ) {
    }

    public static function of(
        Choice|Absent $plan = new Absent(),
        string|Absent $defaultBranch = new Absent(),
        string|Absent $check = new Absent(),
        Path|Absent $gitlabTemplate = new Absent(),
        Json|Absent $buildkiteStep = new Absent(),
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

    /** @return list<Field<Layer>> */
    public static function fields(Origin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $plan = Field::optional('plan', Adapter::choosing(Builtins::ciPlans()), $judges);
        $branch = Field::optional('defaultBranch', Text::of('a branch name'), $judges);
        $check = Field::optional('check', Text::of('a check-run name'), $judges);
        $template = Field::optional('template', Location::path($origin), $judges);
        $step = Field::optional('step', OpenObject::any(), $judges);
        $definition = Field::optional('definition', Location::path($origin), $judges);
        $gitlab = Field::section(
            'gitlab',
            Section::single(
                $template,
                static fn(Path|Absent $path): self => self::of(gitlabTemplate: $path),
            ),
        );
        $buildkite = Field::section(
            'buildkite',
            Section::of(
                static function (Node $buildkite) use ($step, $definition): self|Invalid {
                    $keys = $step->read($buildkite);
                    $pipeline = $definition->read($buildkite);

                    return Reading::built(
                        static fn(): self => self::of(buildkiteStep: $keys->value(), buildkiteDefinition: $pipeline->value()),
                        $keys,
                        $pipeline,
                    );
                },
                $step,
                $definition,
            ),
        );

        return [Field::section(
            'ci',
            Section::of(
                static function (Node $ci) use ($plan, $branch, $check, $gitlab, $buildkite): Layer|Invalid {
                    $readings = [$plan->read($ci), $branch->read($ci), $check->read($ci)];
                    $inner = [$gitlab->read($ci), $buildkite->read($ci)];

                    return Reading::built(
                        static fn(): Layer => Layer::of(self::of(
                            plan: $readings[0]->value(),
                            defaultBranch: $readings[1]->value(),
                            check: $readings[2]->value(),
                        )->over($inner[0]->must())->over($inner[1]->must())),
                        ...$readings,
                        ...$inner,
                    );
                },
                $plan,
                $branch,
                $check,
                $gitlab,
                $buildkite,
            ),
        )];
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

    /** The CI plan, or none, when it is detected from the environment. */
    public function plan(): Choice|Absent
    {
        return $this->plan;
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
    public function buildkiteStep(): Json
    {
        return $this->buildkiteStep instanceof Json ? $this->buildkiteStep : Json::object();
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

    public function written(Origin $origin): Json
    {
        $ci = Json::object();
        $ci = $this->plan instanceof Choice ? $ci->with('plan', $this->plan->written()) : $ci;
        $ci = $this->defaultBranch instanceof Absent ? $ci : $ci->with('defaultBranch', $this->defaultBranch);
        $ci = $this->check instanceof Absent ? $ci : $ci->with('check', $this->check);
        $ci = $this->gitlabTemplate instanceof Path
            ? $ci->with('gitlab', Json::object()->with('template', $origin->written($this->gitlabTemplate)))
            : $ci;
        $buildkite = $this->buildkiteStep instanceof Json ? Json::object()->with('step', $this->buildkiteStep) : Json::object();
        $buildkite = $this->buildkiteDefinition instanceof Path
            ? $buildkite->with('definition', $origin->written($this->buildkiteDefinition))
            : $buildkite;
        $ci = $buildkite->isEmpty() ? $ci : $ci->with('buildkite', $buildkite);

        return $ci->isEmpty() ? Json::object() : Json::object()->with('ci', $ci);
    }

    public function php(Origin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->plan instanceof Choice ? [$this->plan->php('Ci', self::PLANS)] : [],
            ...$this->defaultBranch instanceof Absent
                ? []
                : [sprintf('Ci::defaultBranch(%s)', PhpCalls::literal($this->defaultBranch))],
            ...$this->check instanceof Absent ? [] : [sprintf('Ci::check(%s)', PhpCalls::literal($this->check))],
            ...$this->gitlabTemplate instanceof Path
                ? [sprintf('Ci::gitlabTemplate(%s)', PhpCalls::literal($origin->written($this->gitlabTemplate)))]
                : [],
            ...$this->buildkiteStep instanceof Json
                ? [sprintf('Ci::buildkiteStep(%s)', implode(', ', PhpOptions::of($this->buildkiteStep)))]
                : [],
            ...$this->buildkiteDefinition instanceof Path ? [sprintf(
                'Ci::buildkiteDefinition(%s)',
                PhpCalls::literal($origin->written($this->buildkiteDefinition)),
            )] : [],
        ]);
    }
}
