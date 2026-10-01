<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
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
        $results = Effect::AffectsResults;
        $template = Field::optional('template', Location::path($origin), $results);
        $step = Field::optional('step', StepTemplate::buildkite(), $judges);
        $definition = Field::optional('definition', Location::path($origin), $results);
        $azure = Field::section(
            'azure',
            Section::single(
                Field::optional('definition', Location::path($origin), $results),
                static fn(Path|Absent $path): Ci => Ci::of(azureDefinition: $path),
            ),
        );
        $bitbucket = Field::section(
            'bitbucket',
            Section::single(
                Field::optional('definition', Location::path($origin), $results),
                static fn(Path|Absent $path): Ci => Ci::of(bitbucketDefinition: $path),
            ),
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
                    $gitlab,
                    $buildkite,
                    $azure,
                    $bitbucket,
                ): Layer|Invalid {
                    $readings = [$plan->read($ci), $branch->read($ci), $check->read($ci)];
                    $inner = [$gitlab->read($ci), $buildkite->read($ci), $azure->read($ci), $bitbucket->read($ci)];

                    return Reading::built(
                        static fn(): Layer => Layer::of(self::laid(
                            Ci::of(
                                plan: $readings[0]->value(),
                                defaultBranch: $readings[1]->value(),
                                check: $readings[2]->value(),
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
                $gitlab,
                $buildkite,
                $azure,
                $bitbucket,
            ),
        )];
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
