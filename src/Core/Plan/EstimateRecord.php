<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_keys;

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\ShardEstimate;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A shard's estimate as the plan file writes it beside the shard's
 * `seconds`: where any of it rests on more than a guess, the seconds of each
 * basis, `learned`, `measured` and `guessed`, each where it is more than
 * none; and its `opening` run. A shard that names no basis is a guess of its
 * seconds.
 *
 * @internal the shape of the plan file
 */
final readonly class EstimateRecord
{
    private const string OPENING = 'opening';

    /** @return array<string, float> */
    public static function of(ShardEstimate $estimate): array
    {
        $parts = [];

        foreach (CostBasis::cases() as $basis) {
            $seconds = $estimate->part($basis)->seconds();
            $parts = $seconds > 0.0 ? [...$parts, $basis->value => $seconds] : $parts;
        }

        $parts = array_keys($parts) === [CostBasis::Guessed->value] ? [] : $parts;
        $opening = $estimate->openingRun()->seconds();

        return [...$parts, ...$opening > 0.0 ? [self::OPENING => $opening] : []];
    }

    /** @throws NotInShape */
    public static function read(Node $shard, Seconds $seconds): ShardEstimate
    {
        $parts = ShardEstimate::none();
        $named = false;

        foreach (CostBasis::cases() as $basis) {
            $part = $shard->field($basis->value);
            $named = $named || $part->isPresent();
            $parts = $part->isPresent() ? $parts->with(Estimated::of(Seconds::of($part->number()), $basis)) : $parts;
        }

        $opening = $shard->field(self::OPENING);
        $estimate = $named ? $parts : ShardEstimate::none()->with(Estimated::of($seconds, CostBasis::Guessed));

        return $estimate->opening(Seconds::of($opening->isPresent() ? $opening->number() : 0.0));
    }
}
