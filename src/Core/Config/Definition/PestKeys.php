<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\Preloaded;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Test\Group;

/**
 * How the keys a config writes are read into its `Pest` part (ADR-0002).
 *
 * @internal
 */
final readonly class PestKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $results = Effect::AffectsResults;
        $patch = Field::optional('patch', Flag::boolean(), $results);
        $canary = Field::optional('canary', Text::word('a group name'), $results);
        $bootTail = Field::optional(Pest::BOOT_TAIL, Flag::boolean(), $results);
        $opcache = Field::optional(Pest::WORKER_OPCACHE, Flag::boolean(), $results);
        $schemata = Field::optional(Pest::SCHEMATA, Flag::boolean(), $results);
        $bootMutants = Field::optional(Pest::BOOT_MUTANTS, Flag::boolean(), $results);
        $bootChunks = Field::optional(Pest::BOOT_CHUNKS, Flag::boolean(), Effect::JudgesOrReportsOnly);
        $toggles = ToggleFields::in('pest');
        $narrowedKills = Field::optional(Pest::NARROWED_KILLS, Enumerated::of(NarrowedKillTrust::cases()), $results);
        $longFilter = Field::optional(Pest::LONG_FILTER, Enumerated::of(LongFilter::cases()), $results);
        $preloaded = Field::optional(Pest::PRELOADED, Enumerated::of(Preloaded::cases()), $results);

        return [Field::section(
            'pest',
            Section::of(
                static function (Node $pest) use (
                    $patch,
                    $canary,
                    $bootTail,
                    $opcache,
                    $schemata,
                    $bootMutants,
                    $bootChunks,
                    $toggles,
                    $narrowedKills,
                    $longFilter,
                    $preloaded,
                ): Layer|Invalid {
                    $switched = $toggles->read($pest);
                    $vouching = $narrowedKills->read($pest);
                    $selecting = $longFilter->read($pest);
                    $rejudging = $preloaded->read($pest);
                    $patched = $patch->read($pest);
                    $group = $canary->read($pest);
                    $booted = $bootTail->read($pest);
                    $cached = $opcache->read($pest);
                    $compiled = $schemata->read($pest);
                    $forked = $bootMutants->read($pest);
                    $kept = $bootChunks->read($pest);
                    $named = $group->value();

                    return Reading::built(
                        static fn(): Layer => Layer::of(
                            Pest::of(
                                $patched->value(),
                                $named instanceof Absent ? $named : Group::named($named),
                                $booted->value(),
                                $cached->value(),
                                $compiled->value(),
                                $forked->value(),
                                $vouching->value(),
                                $selecting->value(),
                                $kept->value(),
                                $rejudging->value(),
                            ),
                            $toggles->set($switched),
                        ),
                        $patched,
                        $group,
                        $booted,
                        $cached,
                        $compiled,
                        $forked,
                        $vouching,
                        $selecting,
                        $kept,
                        $rejudging,
                        ...$switched,
                    );
                },
                $patch,
                $canary,
                $bootTail,
                $opcache,
                $schemata,
                $bootMutants,
                $bootChunks,
                $narrowedKills,
                $longFilter,
                $preloaded,
                ...$toggles->fields(),
            ),
        )];
    }
}
