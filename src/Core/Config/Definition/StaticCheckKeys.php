<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How the keys a config writes are read into its `StaticCheck` part
 * (ADR-0002). All three affect results: a mutant the analyser rejects is
 * killed, and a check stopped at its deadline rejects none.
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

        return [Field::section(
            'staticCheck',
            Section::of(
                static function (Node $staticCheck) use ($tool, $config, $seconds, $before): Layer|Invalid {
                    $chosen = $tool->read($staticCheck);
                    $read = $config->read($staticCheck);
                    $limit = $seconds->read($staticCheck);
                    $placed = $before->read($staticCheck);
                    $cap = $limit->value();

                    return Reading::built(
                        static fn(): Layer => Layer::of(StaticCheck::of(
                            $chosen->value(),
                            $read->value(),
                            $cap instanceof Absent ? $cap : Seconds::of($cap),
                            $placed->value(),
                        )),
                        $chosen,
                        $read,
                        $limit,
                        $placed,
                    );
                },
                $tool,
                $config,
                $seconds,
                $before,
            ),
        )];
    }
}
