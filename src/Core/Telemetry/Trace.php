<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Telemetry;

use function hash;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\ShardTiming;

use function sprintf;

/**
 * A run's spans: `plan`, `shard <n>` with a child for each step its time
 * went to, named by the step, and `verdict`, each measured, in one trace whose id the run
 * names. A span's id is the first 8 bytes of `sha256(<trace id>/<path>)`,
 * so the gate draws no random number (ADR-0016, decision 15).
 */
final readonly class Trace
{
    /** The attribute that names a shard. */
    public const string SHARD = 'mutation_gate.shard';
    /** A step's path under its shard: the shard, the step's place among the shard's steps, and the step. */
    private const string STEP = '%s/%d/%s';
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
     * A shard's span, from its start to its end, with a span under it for
     * each step its time went to, in the order they began.
     *
     * @param  array<string, string|int> $attributes
     * @return list<Span>
     */
    private static function shard(string $trace, ShardTiming $shard, array $attributes): array
    {
        $name = sprintf('shard %d', $shard->shard());
        $id = self::spanId($trace, $name);
        $own = [...$attributes, self::SHARD => $shard->shard()];
        $spans = [Span::of($name, $id, '', $shard->whole(), $own)];

        foreach ($shard->steps() as $at => $step) {
            $path = sprintf(self::STEP, $name, $at, $step->step()->value);
            $spans[] = Span::of($step->step()->value, self::spanId($trace, $path), $id, $shard->phaseOf($step), $own);
        }

        return $spans;
    }
}
