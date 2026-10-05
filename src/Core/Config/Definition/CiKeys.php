<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use Closure;
use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

/** How the keys a config writes are read into its `Ci` part (ADR-0002). */
final readonly class CiKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $plan = Field::optional('plan', Adapter::choosing(Builtins::ciPlans($origin)), $judges);
        $branch = Field::optional('defaultBranch', Text::of('a branch name'), $judges);
        $check = Field::optional('check', Text::of('a check-run name'), $judges);
        $trust = Field::optional('trustMergedPullRequests', Flag::boolean(), $judges);
        $results = Effect::AffectsResults;
        $template = Field::optional('template', Location::path($origin), $results);
        $step = Field::optional('step', StepTemplate::buildkite(), $judges);
        $definition = Field::optional(CiJob::DEFINITION, Location::path($origin), $results);
        $azure = self::defined('azure', $origin, static fn(Path|Absent $path): Ci => Ci::of(azureDefinition: $path));
        $bitbucket = self::defined(
            'bitbucket',
            $origin,
            static fn(Path|Absent $path): Ci => Ci::of(bitbucketDefinition: $path),
        );
        $jenkins = self::defined(
            'jenkins',
            $origin,
            static fn(Path|Absent $path): Ci => Ci::of(jenkinsDefinition: $path),
        );
        $gitlab = Field::section(
            'gitlab',
            Section::single(
                $template,
                static fn(Path|Absent $path): Ci => Ci::of(gitlabTemplate: $path),
            ),
        );
        $buildkite = Field::section(
            'buildkite',
            Section::of(
                static function (Node $buildkite) use ($step, $definition): Ci|Invalid {
                    $keys = $step->read($buildkite);
                    $pipeline = $definition->read($buildkite);

                    return Reading::built(
                        static fn(): Ci => Ci::of(
                            buildkiteStep: self::step($keys->value()),
                            buildkiteDefinition: $pipeline->value(),
                        ),
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
                static function (Node $ci) use (
                    $plan,
                    $branch,
                    $check,
                    $trust,
                    $gitlab,
                    $buildkite,
                    $azure,
                    $bitbucket,
                    $jenkins,
                ): Layer|Invalid {
                    $readings = [$plan->read($ci), $branch->read($ci), $check->read($ci), $trust->read($ci)];
                    $inner = [
                        $gitlab->read($ci),
                        $buildkite->read($ci),
                        $azure->read($ci),
                        $bitbucket->read($ci),
                        $jenkins->read($ci),
                    ];

                    return Reading::built(
                        static fn(): Layer => Layer::of(self::laid(
                            Ci::of(
                                plan: $readings[0]->value(),
                                defaultBranch: $readings[1]->value(),
                                check: $readings[2]->value(),
                                trustMergedPullRequests: $readings[3]->value(),
                            ),
                            ...$inner,
                        )),
                        ...$readings,
                        ...$inner,
                    );
                },
                $plan,
                $branch,
                $check,
                $trust,
                $gitlab,
                $buildkite,
                $azure,
                $bitbucket,
                $jenkins,
            ),
        )];
    }

    /**
     * A CI's section that holds only `definition`, the path of the pipeline file that runs the gate there, which
     * affects results (ADR-0024 decision 3).
     *
     * @param  Closure(Path|Absent): Ci $build
     * @return Field<Ci>
     */
    private static function defined(string $ci, PathOrigin $origin, Closure $build): Field
    {
        return Field::section(
            $ci,
            Section::single(
                Field::optional(CiJob::DEFINITION, Location::path($origin), Effect::AffectsResults),
                $build,
            ),
        );
    }

    private static function step(Json|Absent $keys): BuildkiteStep|Absent
    {
        return $keys instanceof Json ? BuildkiteStep::of($keys) : $keys;
    }

    /**
     * The section's own keys, with each CI's section laid over them in turn.
     *
     * @param Reading<Ci> ...$sections
     */
    private static function laid(Ci $ci, Reading ...$sections): Ci
    {
        foreach ($sections as $section) {
            $ci = $ci->over($section->must());
        }

        return $ci;
    }
}
