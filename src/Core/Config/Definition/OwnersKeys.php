<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\OwnerSettings;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Owner\OwnerMention;

/** How the `owners` keys a config writes are read into its `Floors` part (ADR-0022). */
final readonly class OwnersKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $floors = Field::optional('floors', NumberMap::of(Number::percent()), Effect::JudgesOrReportsOnly);
        $mention = Field::optional('mention', Enumerated::of(OwnerMention::cases()), Effect::JudgesOrReportsOnly);

        return [Field::section(
            'owners',
            Section::of(
                static function (Node $owners) use ($floors, $mention): Layer|Invalid {
                    $floored = $floors->read($owners);
                    $mentioned = $mention->read($owners);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Floors::of(
                            owners: OwnerSettings::of($floored->value(), $mentioned->value()),
                        )),
                        $floored,
                        $mentioned,
                    );
                },
                $floors,
                $mention,
            ),
        )];
    }
}
