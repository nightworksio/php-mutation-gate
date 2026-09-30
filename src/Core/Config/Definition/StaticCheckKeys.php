<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Origin;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the keys a config writes are read into its `StaticCheck` part
 * (ADR-0002). Both affect results: a mutant the analyser rejects is killed.
 */
final readonly class StaticCheckKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(Origin $origin): array
    {
        $results = Effect::AffectsResults;
        $tool = Field::optional('tool', Adapter::choosing(Builtins::staticCheckers()), $results);
        $config = Field::optional('config', Location::path($origin), $results);

        return [Field::section(
            'staticCheck',
            Section::of(
                static function (Node $staticCheck) use ($tool, $config): Layer|Invalid {
                    $chosen = $tool->read($staticCheck);
                    $read = $config->read($staticCheck);

                    return Reading::built(
                        static fn(): Layer => Layer::of(StaticCheck::of($chosen->value(), $read->value())),
                        $chosen,
                        $read,
                    );
                },
                $tool,
                $config,
            ),
        )];
    }
}
