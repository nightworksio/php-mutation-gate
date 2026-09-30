<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\Config\Ignores;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Config\Origin;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Time\Day;

/** How the keys a config writes are read into its `Ignores` part (ADR-0002). */
final readonly class IgnoresKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(Origin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $entries = Field::optional('entries', Items::of(self::entry($origin)), $judges);
        $maxDays = Field::optional('maxDays', Integer::atLeast(1), $judges);
        $native = Field::optional('native', Enumerated::of(NativeMarkers::cases()), $judges);

        return [
            Field::section(
                'ignores',
                Section::of(
                    static function (Node $ignores) use ($entries, $maxDays, $native): Layer|Invalid {
                        $ignored = $entries->read($ignores);
                        $days = $maxDays->read($ignores);
                        $markers = $native->read($ignores);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Ignores::of(
                                $ignored->value(),
                                $days->value(),
                                $markers->value(),
                            )),
                            $ignored,
                            $days,
                            $markers,
                        );
                    },
                    $entries,
                    $maxDays,
                    $native,
                ),
            ),
            Field::section(
                'equivalence',
                Section::single(
                    Field::optional('static', Flag::boolean(), $judges),
                    static fn(bool|Absent $proven): Layer => Layer::of(Ignores::of(staticEquivalence: $proven)),
                ),
            ),
        ];
    }

    /**
     * An `ignores.entries` entry: one mutant by its id, or a mutator in the paths a glob matches.
     *
     * @return Section<Ignored>
     */
    private static function entry(Origin $origin): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $mutant = Field::optional('mutant', Identifier::mutant(), $judges);
        $path = Field::optional('path', Pattern::glob($origin), $judges);
        $mutator = Field::optional('mutator', Text::of('a mutator or a family of them'), $judges);
        $reason = Field::required('reason', Text::of('a reason'), $judges);
        $expires = Field::optional('expires', Date::written(), $judges);

        return Section::of(
            static function (Node $entry) use ($mutant, $path, $mutator, $reason, $expires): Ignored|Invalid {
                $id = $mutant->read($entry);
                $glob = $path->read($entry);
                $family = $mutator->read($entry);
                $why = $reason->read($entry);
                $until = $expires->read($entry);

                return Reading::built(
                    static fn(): Ignored|Invalid => self::ignored(
                        $entry,
                        $id->value(),
                        $glob->value(),
                        $family->value(),
                        $why->must(),
                        $until->value(),
                    ),
                    $id,
                    $glob,
                    $family,
                    $why,
                    $until,
                );
            },
            $mutant,
            $path,
            $mutator,
            $reason,
            $expires,
        )->oneOf([['mutant'], ['path', 'mutator']]);
    }

    private static function ignored(
        Node $entry,
        MutantId|Absent $mutant,
        Glob|Absent $path,
        string|Absent $mutator,
        string $reason,
        Day|Absent $expires,
    ): Ignored|Invalid {
        return match (true) {
            $mutant instanceof MutantId && $path instanceof Absent && $mutator instanceof Absent
                => IgnoredMutant::of($mutant, $reason, $expires),
            $mutant instanceof Absent && ! $path instanceof Absent && ! $mutator instanceof Absent
                => IgnoredPattern::of($path, $mutator, $reason, $expires),
            default => Invalid::because(
                Problem::at($entry->at(), 'expected either mutant, or path and mutator, but not both'),
            ),
        };
    }
}
