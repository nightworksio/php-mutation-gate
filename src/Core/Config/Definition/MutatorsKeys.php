<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutatorNamePattern;

use function sprintf;

/**
 * How the keys a config writes are read into the mutators of its `Setup`
 * part (ADR-0021).
 * Both affect results: they decide which mutants are made. Each list keeps
 * an entry once.
 */
final readonly class MutatorsKeys
{
    private const string SET = "a mutator set's name: lower case letters and digits, joined by single hyphens";

    private const string MUTATOR = "a mutator's name, <set>/<Name>";

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $results = Effect::AffectsResults;
        $named = static fn(string $name): string => $name;
        $set = Text::matching(self::SET, sprintf('^%s$', MutatorNamePattern::SET));
        $mutator = Text::matching(
            self::MUTATOR,
            sprintf('^%s/%s$', MutatorNamePattern::SET, MutatorNamePattern::OWN),
        );
        $sets = Field::optional('sets', Items::distinct($set, $named), $results);
        $except = Field::optional('except', Items::distinct($mutator, $named), $results);

        return [Field::section(
            'mutators',
            Section::of(
                static function (Node $mutators) use ($sets, $except): Layer|Invalid {
                    $on = $sets->read($mutators);
                    $off = $except->read($mutators);

                    return Reading::built(
                        static fn(): Layer => Layer::of(
                            Setup::of(mutators: Mutators::of($on->value(), $off->value())),
                        ),
                        $on,
                        $off,
                    );
                },
                $sets,
                $except,
            ),
        )];
    }
}
