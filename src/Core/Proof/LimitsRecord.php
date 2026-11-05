<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;

use NightWorksIO\MutationGate\Core\Cost\ShardsAtOnce;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Runner\Multiple;

/**
 * What a ledger learned for the limits a config has it learn, as its
 * optional `kills` and `concurrency` sections hold it (ADR-0073): each
 * of the newest kills' time over its covering tests' own time, and each of
 * the newest runs' most shards at once, oldest first.
 *
 * Reading keeps each positive number and drops anything else, never
 * repairing it; a ledger without the sections learned nothing, which has a
 * learned limit take its standard, never a verdict, so the ledger's format
 * does not count them.
 *
 * @internal the shape of the `kills` and `concurrency` sections of the ledger file
 */
final readonly class LimitsRecord
{
    public const string KILLS = 'kills';

    public const string CONCURRENCY = 'concurrency';

    /**
     * The sections a ledger writes of what it learned for the limits, each
     * only where it learned some of it.
     *
     * @return array<string, list<float>|list<int>>
     */
    public static function of(LimitHistory $history): array
    {
        $kills = array_map(static fn(Multiple $kill): float => $kill->times(), [...$history->kills()]);
        $runs = array_map(static fn(ShardsAtOnce $run): int => $run->count(), [...$history->concurrency()]);

        return [
            ...$kills === [] ? [] : [self::KILLS => $kills],
            ...$runs === [] ? [] : [self::CONCURRENCY => $runs],
        ];
    }

    /** What a ledger's two sections hold; nothing of either it holds none of. */
    public static function read(Node $kills, Node $concurrency): LimitHistory
    {
        $ratios = [];
        $runs = [];

        foreach (self::itemsOf($kills) as $kill) {
            $ratios = match ($kill->kind()) {
                Kind::Integer => [...$ratios, Multiple::of($kill->integer())],
                Kind::Number => [...$ratios, Multiple::of($kill->number())],
                Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Boolean, Kind::Null, Kind::Nothing => $ratios,
            };
        }

        foreach (self::itemsOf($concurrency) as $run) {
            $runs = $run->kind() === Kind::Integer ? [...$runs, ShardsAtOnce::of($run->integer())] : $runs;
        }

        return LimitHistory::none()->afterKills(...$ratios)->afterRuns(...$runs);
    }

    /** @return list<Node> */
    private static function itemsOf(Node $section): array
    {
        try {
            return $section->isPresent() ? $section->items() : [];
        } catch (NotInShape) {
            return [];
        }
    }
}
