<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\IdenticalReuse;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\OwnScopeTrust;
use NightWorksIO\MutationGate\Core\Config\Reuse;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the `reuse` keys a config writes are read into its `Ci` part (ADR-0139, ADR-0198).
 *
 * @internal
 */
final readonly class ReuseKeys
{
    /** What `reuse.environment` lists. */
    private const string VARIABLE_NAME = 'a variable name';

    /** A variable's name, as a shell spells one. */
    private const string VARIABLE = '^[A-Za-z_][A-Za-z0-9_]*$';

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $decides = Effect::DecidesHowTheGateRuns;
        $identical = Field::optional('identical', Enumerated::of(IdenticalReuse::cases()), $decides);
        $forks = Field::optional('forks', Flag::boolean(), $decides);
        $ownScope = Field::optional('ownScopeProofs', Enumerated::of(OwnScopeTrust::cases()), $decides);
        $delta = Field::optional('delta', Flag::boolean(), $decides);
        $environment = Field::optional(
            'environment',
            Items::distinct(
                Text::matching(self::VARIABLE_NAME, self::VARIABLE),
                static fn(string $name): string => $name,
            ),
            $decides,
        );
        $passes = Field::optional('passes', Integer::atLeast(1), Effect::JudgesOrReportsOnly);

        return [Field::section(
            'reuse',
            Section::of(
                static function (Node $reuse) use (
                    $identical,
                    $forks,
                    $ownScope,
                    $delta,
                    $environment,
                    $passes,
                ): Layer|Invalid {
                    $where = $identical->read($reuse);
                    $forked = $forks->read($reuse);
                    $own = $ownScope->read($reuse);
                    $changed = $delta->read($reuse);
                    $named = $environment->read($reuse);
                    $kept = $passes->read($reuse);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Ci::of(reuse: Reuse::of(
                            $where->value(),
                            $forked->value(),
                            $own->value(),
                            $changed->value(),
                            $named->value(),
                            $kept->value(),
                        ))),
                        $where,
                        $forked,
                        $own,
                        $changed,
                        $named,
                        $kept,
                    );
                },
                $identical,
                $forks,
                $ownScope,
                $delta,
                $environment,
                $passes,
            ),
        )];
    }
}
