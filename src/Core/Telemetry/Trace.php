<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Telemetry;

use function hash;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\ShardTiming;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A run's spans: `plan`, `shard <n>` with the children `opening run` and
 * `mutate`, and `verdict`, each measured, in one trace whose id the run
 * names. A span's id is the first 8 bytes of `sha256(<trace id>/<path>)`,
 * so the gate draws no random number (ADR-0016, decision 15).
 */
final readonly class Trace
{
    /** The attribute that names a shard. */
    public const string SHARD = 'mutation_gate.shard';
    /** Hex digits in a span id: 8 bytes. */
    private const int SPAN_ID = 16;

    /**
     * Each span of the run, each carrying these attributes.
     *
     * @param  array<string, string|int> $attributes by name
     * @return list<Span>
     */
    public static function spans(RunTimings $timings, array $attributes): array
    {
        $trace = $timings->traceId();
        $plan = $timings->plan();
        $verdict = $timings->verdict();
        $spans = $plan instanceof Phase ? [self::top($trace, 'plan', $plan, $attributes)] : [];

        foreach ($timings->shards() as $shard) {
            $spans = [...$spans, ...self::shard($trace, $shard, $attributes)];
        }

        return $verdict instanceof Phase ? [...$spans, self::top($trace, 'verdict', $verdict, $attributes)] : $spans;
    }

    /** A span's id: the first 8 bytes of the hash of its trace and its path. */
    public static function spanId(string $trace, string $path): string
    {
        return mb_substr(hash('sha256', sprintf('%s/%s', $trace, $path)), 0, self::SPAN_ID);
    }

    /** @param array<string, string|int> $attributes */
    private static function top(string $trace, string $name, Phase $phase, array $attributes): Span
    {
        return Span::of($name, self::spanId($trace, $name), '', $phase, $attributes);
    }

    /**
     * A shard's span, from its opening run's start to its mutation's end, with those two under it.
     *
     * @param  array<string, string|int> $attributes
     * @return list<Span>
     */
    private static function shard(string $trace, ShardTiming $shard, array $attributes): array
    {
        $name = sprintf('shard %d', $shard->shard());
        $id = self::spanId($trace, $name);
        $opening = $shard->openingRun();
        $mutate = $shard->mutate();
        $whole = Phase::of(
            $opening->start(),
            Seconds::of($opening->duration()->seconds() + $mutate->duration()->seconds()),
        );
        $own = [...$attributes, self::SHARD => $shard->shard()];

        return [
            Span::of($name, $id, '', $whole, $own),
            Span::of('opening run', self::spanId($trace, sprintf('%s/opening run', $name)), $id, $opening, $own),
            Span::of('mutate', self::spanId($trace, sprintf('%s/mutate', $name)), $id, $mutate, $own),
        ];
    }
}
