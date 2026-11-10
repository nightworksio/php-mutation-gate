<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\CacheStore;
use NightWorksIO\MutationGate\Core\Config\CacheTrust;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\GraphUse;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Outbreak;
use NightWorksIO\MutationGate\Core\Config\OutbreakCache;
use NightWorksIO\MutationGate\Core\Config\OutbreakServe;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the `outbreak` keys a config writes are read into its `Shards` part
 * (ADR-0118). The binary's output is the gate's own, so changing most of them
 * re-runs nothing, as a key that judges or reports only does; the static
 * graph decides what a run reaches (ADR-0132).
 *
 * @internal
 */
final readonly class OutbreakKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $none = Effect::JudgesOrReportsOnly;
        $enabled = Field::optional(Outbreak::ENABLED, Flag::boolean(), $none);
        $binary = Field::optional(Outbreak::BINARY, Text::of('a path to the outbreak binary'), $none);
        $timeout = Field::optional(Outbreak::TIMEOUT, Duration::written(), $none);
        $threads = Field::optional(Outbreak::THREADS, Integer::atLeast(1), $none);
        $cache = Field::section(OutbreakCache::OPTION, self::cache());
        $equivalence = Field::optional(Outbreak::EQUIVALENCE, Flag::boolean(), Effect::AffectsResults);
        $forkStates = Field::optional(Outbreak::FORK_STATES, Flag::boolean(), Effect::DecidesHowTheGateRuns);
        $recheck = Field::optional(Outbreak::RECHECK_TOUCHED_KILLS, Flag::boolean(), Effect::AffectsResults);
        $graph = Field::optional(Outbreak::GRAPH, Enumerated::of(GraphUse::cases()), Effect::DecidesHowTheGateRuns);
        $serve = Field::section(OutbreakServe::OPTION, self::serve());
        $lines = Field::optional(Outbreak::LINES, Flag::boolean(), Effect::DecidesHowTheGateRuns);
        $steps = Field::optional(Outbreak::STEPS, Flag::boolean(), Effect::AffectsResults);
        $stepFloor = Field::optional(Outbreak::STEP_FLOOR, Integer::atLeast(1), Effect::AffectsResults);

        return [Field::section(
            Outbreak::OPTION,
            Section::of(
                static function (Node $outbreak) use (
                    $enabled,
                    $binary,
                    $timeout,
                    $threads,
                    $cache,
                    $equivalence,
                    $forkStates,
                    $recheck,
                    $graph,
                    $serve,
                    $lines,
                    $steps,
                    $stepFloor,
                ): Layer|Invalid {
                    $on = $enabled->read($outbreak);
                    $at = $binary->read($outbreak);
                    $within = $timeout->read($outbreak);
                    $cores = $threads->read($outbreak);
                    $kept = $cache->read($outbreak);
                    $proves = $equivalence->read($outbreak);
                    $counts = $forkStates->read($outbreak);
                    $rechecks = $recheck->read($outbreak);
                    $acts = $graph->read($outbreak);
                    $serves = $serve->read($outbreak);
                    $records = $lines->read($outbreak);
                    $counted = $steps->read($outbreak);
                    $floor = $stepFloor->read($outbreak);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Shards::of(outbreak: Outbreak::of(
                            $on->value(),
                            $at->value(),
                            $within->value(),
                            $cores->value(),
                            $kept->value(),
                            $proves->value(),
                            $counts->value(),
                            $rechecks->value(),
                            $acts->value(),
                            $serves->value(),
                            $records->value(),
                            $counted->value(),
                            $floor->value(),
                        ))),
                        $on,
                        $at,
                        $within,
                        $cores,
                        $kept,
                        $proves,
                        $counts,
                        $rechecks,
                        $acts,
                        $serves,
                        $records,
                        $counted,
                        $floor,
                    );
                },
                $enabled,
                $binary,
                $timeout,
                $threads,
                $cache,
                $equivalence,
                $forkStates,
                $recheck,
                $graph,
                $serve,
                $lines,
                $steps,
                $stepFloor,
            ),
        )];
    }

    /**
     * Whether `outbreak serve` holds the project's state, and its clocks
     * (ADR-0133): each judges or reports only.
     *
     * @return Section<OutbreakServe>
     */
    private static function serve(): Section
    {
        $none = Effect::JudgesOrReportsOnly;
        $enabled = Field::optional(OutbreakServe::ENABLED, Flag::boolean(), $none);
        $snapshot = Field::optional(OutbreakServe::SNAPSHOT_EVERY, Duration::written(), $none);
        $sync = Field::optional(OutbreakServe::SYNC_EVERY, Duration::written(), $none);

        return Section::of(
            static function (Node $serve) use ($enabled, $snapshot, $sync): OutbreakServe|Invalid {
                $on = $enabled->read($serve);
                $every = $snapshot->read($serve);
                $synced = $sync->read($serve);

                return Reading::built(
                    static fn(): OutbreakServe => OutbreakServe::of($on->value(), $every->value(), $synced->value()),
                    $on,
                    $every,
                    $synced,
                );
            },
            $enabled,
            $snapshot,
            $sync,
        );
    }

    /**
     * Where `outbreak cache` keeps its packs and which the default branch
     * reads (ADR-0131): each decides how the gate runs.
     *
     * @return Section<OutbreakCache>
     */
    private static function cache(): Section
    {
        $decides = Effect::DecidesHowTheGateRuns;
        $store = Field::optional(OutbreakCache::STORE, Enumerated::of(CacheStore::cases()), $decides);
        $registry = Field::optional(OutbreakCache::REGISTRY, Text::of('an OCI repository'), $decides);
        $trust = Field::optional(OutbreakCache::TRUST, Enumerated::of(CacheTrust::cases()), $decides);

        return Section::of(
            static function (Node $cache) use ($store, $registry, $trust): OutbreakCache|Invalid {
                $kept = $store->read($cache);
                $named = $registry->read($cache);
                $read = $trust->read($cache);

                return Reading::built(
                    static fn(): OutbreakCache => OutbreakCache::of($kept->value(), $named->value(), $read->value()),
                    $kept,
                    $named,
                    $read,
                );
            },
            $store,
            $registry,
            $trust,
        );
    }
}
