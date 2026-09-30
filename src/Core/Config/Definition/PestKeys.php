<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Test\Group;

/** How the keys a config writes are read into its `Pest` part (ADR-0002). */
final readonly class PestKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $results = Effect::AffectsResults;
        $patch = Field::optional('patch', Flag::boolean(), $results);
        $canary = Field::optional('canary', Text::of('a group name'), $results);

        return [Field::section(
            'pest',
            Section::of(
                static function (Node $pest) use ($patch, $canary): Layer|Invalid {
                    $patched = $patch->read($pest);
                    $group = $canary->read($pest);
                    $named = $group->value();

                    return Reading::built(
                        static fn(): Layer => Layer::of(Pest::of(
                            $patched->value(),
                            $named instanceof Absent ? $named : Group::named($named),
                        )),
                        $patched,
                        $group,
                    );
                },
                $patch,
                $canary,
            ),
        )];
    }
}
