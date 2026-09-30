<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Format\Node;

/** How the keys a config writes are read into its `Local` part (ADR-0002). */
final readonly class LocalKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $watch = Field::optional('watchBudget', Duration::written(), $judges);
        $prePush = Field::optional('prePushBudget', Duration::written(), $judges);

        return [Field::section(
            'local',
            Section::of(
                static function (Node $local) use ($watch, $prePush): Layer|Invalid {
                    $watching = $watch->read($local);
                    $pushing = $prePush->read($local);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Local::of($watching->value(), $pushing->value())),
                        $watching,
                        $pushing,
                    );
                },
                $watch,
                $prePush,
            ),
        )];
    }
}
