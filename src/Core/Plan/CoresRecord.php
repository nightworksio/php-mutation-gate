<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

/**
 * The cores of a shard's machine as its result's `measured` writes them, as
 * `machineCores` (ADR-0230); a result of an earlier gate holds none.
 *
 * @internal the shape of a shard result's `measured`
 */
final readonly class CoresRecord
{
    private const string FIELD = 'machineCores';

    /** @return array{machineCores?: int} */
    public static function of(ProcessCount|NotGiven $cores): array
    {
        return $cores instanceof ProcessCount ? [self::FIELD => $cores->count()] : [];
    }

    /** @throws NotInShape */
    public static function read(Node $measured): ProcessCount|NotGiven
    {
        $cores = $measured->field(self::FIELD);

        if (! $cores->isPresent()) {
            return NotGiven::value();
        }

        $count = $cores->integer();

        return $count > 0 ? ProcessCount::of($count) : throw NotInShape::at($cores->at(), 'a count of cores');
    }
}
