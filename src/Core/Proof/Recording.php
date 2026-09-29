<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_filter;
use function count;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

use function sprintf;

/**
 * Whether a unit's result becomes a proof. Only a unit that ran to the end is
 * recorded: none of its mutants left unjudged by a budget or a stopped runner,
 * and none flaky. A unit whose only trouble was mutants that timed out, or
 * that the runner skipped as too slow to judge, ran to the end and is
 * recorded. A unit with no key is never recorded, and a shard that cannot
 * judge records nothing, so it never reaches here.
 */
final readonly class Recording
{
    /** @param Mutants $mutants every mutant of the unit, as the runner reported them */
    public static function of(
        Digest|Unkeyed $key,
        Path $unit,
        Mutants $mutants,
        MutantIds $flaky,
        Run $run,
    ): Proof|NotRecorded {
        if ($key instanceof Unkeyed) {
            return NotRecorded::because($key->why());
        }

        $unfinished = self::unfinishedIn($mutants, $flaky);

        return $unfinished === 0
            ? Proof::of($key, $unit, $mutants, $run)
            : NotRecorded::because(sprintf(
                '%s did not run to the end: %d of its mutants are unjudged or flaky.',
                $unit->value(),
                $unfinished,
            ));
    }

    private static function unfinishedIn(Mutants $mutants, MutantIds $flaky): int
    {
        return count(array_filter(
            iterator_to_array($mutants, preserve_keys: false),
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Unjudged
                || $flaky->has($mutant->id()),
        ));
    }
}
