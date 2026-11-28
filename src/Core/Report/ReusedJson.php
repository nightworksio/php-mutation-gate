<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\StandsBecause;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;

/**
 * The JSON report's `reused`: the pass whose verdict a run reused, its
 * inputs identical (ADR-0198): its commit, where it was read, the digest of
 * its inputs and the run that judged it, where its record names one.
 *
 * @internal the shape of the JSON report
 *
 * @phpstan-type ReusedEntry array{commit: string, from: string, inputs: string, run?: string}
 */
final readonly class ReusedJson
{
    /** @return array{reused?: ReusedEntry} the pass of a verdict that stands as reused; nothing for any other run */
    public static function of(Unchanged|NotGiven $standing): array
    {
        return $standing instanceof Unchanged && $standing->because() === StandsBecause::IdenticalInputs
            ? self::reusing($standing)
            : [];
    }

    /** @return array{reused?: ReusedEntry} */
    private static function reusing(Unchanged $standing): array
    {
        $passed = $standing->base();
        $judging = $passed->judging();
        $inputs = $judging->inputs();

        return $inputs instanceof Digest
            ? ['reused' => [
                'commit' => $passed->commit()->name(),
                'from' => $standing->from(),
                'inputs' => $inputs->value(),
                ...$judging->address() === '' ? [] : ['run' => $judging->address()],
            ]]
            : [];
    }
}
