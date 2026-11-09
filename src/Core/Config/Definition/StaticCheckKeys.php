<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Config\TestsTime;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How the keys a config writes are read into its `StaticCheck` part
 * (ADR-0002). The tool, its config and its seconds affect results: a mutant
 * the analyser rejects is killed, and a check stopped at its deadline rejects
 * none.
 *
 * @internal
 */
final readonly class StaticCheckKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        $results = Effect::AffectsResults;
        $tool = Field::optional('tool', Adapter::choosing(Builtins::staticCheckers($origin)), $results);
        $config = Field::optional('config', Location::path($origin), $results);
        $seconds = Field::optional('seconds', Integer::atLeast(1), $results);
        $before = Field::optional('before', Flag::boolean(), Effect::JudgesOrReportsOnly);
        $judges = Effect::JudgesOrReportsOnly;
        $testsTime = Field::optional('testsTime', Enumerated::of(TestsTime::cases()), $judges);
        $opcache = Field::section('opcache', self::opcache());
        $dependents = Field::optional('dependents', Integer::atLeast(0), $judges);
        $preCheckAfter = Field::optional('preCheckAfter', Integer::atLeast(0), $judges);
        $toggles = ToggleFields::in('staticCheck');
        $php = Field::optional('php', OpenObject::any(), $judges);

        return [Field::section(
            'staticCheck',
            Section::of(
                static function (Node $staticCheck) use (
                    $tool,
                    $config,
                    $seconds,
                    $before,
                    $testsTime,
                    $opcache,
                    $dependents,
                    $preCheckAfter,
                    $toggles,
                    $php,
                ): Layer|Invalid {
                    $switched = $toggles->read($staticCheck);
                    $ini = $php->read($staticCheck);
                    $chosen = $tool->read($staticCheck);
                    $read = $config->read($staticCheck);
                    $limit = $seconds->read($staticCheck);
                    $placed = $before->read($staticCheck);
                    $weighed = $testsTime->read($staticCheck);
                    $compiled = $opcache->read($staticCheck);
                    $following = $dependents->read($staticCheck);
                    $learning = $preCheckAfter->read($staticCheck);
                    $cap = $limit->value();

                    return Reading::built(
                        static fn(): Layer => Layer::of(StaticCheck::of(
                            $chosen->value(),
                            $read->value(),
                            $cap instanceof Absent ? $cap : Seconds::of($cap),
                            $placed->value(),
                            $weighed->value(),
                            dependents: $following->value(),
                            preCheckAfter: $learning->value(),
                            php: $ini->value(),
                        )->over($compiled->must()), $toggles->set($switched)),
                        $chosen,
                        $read,
                        $limit,
                        $placed,
                        $weighed,
                        $compiled,
                        $following,
                        $learning,
                        $ini,
                        ...$switched,
                    );
                },
                $tool,
                $config,
                $seconds,
                $before,
                $testsTime,
                $opcache,
                $dependents,
                $preCheckAfter,
                $php,
                ...$toggles->fields(),
            ),
        )];
    }

    /** @return Section<StaticCheck> */
    private static function opcache(): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $batch = Field::optional('batch', Integer::atLeast(1), $judges);
        $limit = Field::optional('limit', Duration::written(), $judges);

        return Section::of(
            static function (Node $at) use ($batch, $limit): StaticCheck|Invalid {
                $programs = $batch->read($at);
                $seconds = $limit->read($at);

                return Reading::built(
                    static fn(): StaticCheck => StaticCheck::of(
                        opcacheBatch: $programs->value(),
                        opcacheLimit: $seconds->value(),
                    ),
                    $programs,
                    $seconds,
                );
            },
            $batch,
            $limit,
        );
    }
}
