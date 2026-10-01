<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_key_first;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Reason;

/** The mutants a run of Pest made again, matched to those it was asked to run again. */
final readonly class FoundAgain
{
    /** Why a mutant run again is unjudged: Pest made no mutant with its id. */
    public const string NOT_FOUND_AGAIN = 'Run again alone, Pest made no mutant with this id.';

    /**
     * Each mutant as the run found it again, by Pest's id, under the gate's
     * id the first run gave it; or unjudged where the run made no such
     * mutant. A run that makes only some of a file's mutants numbers those
     * that share a change among themselves, so its own gate ids can name
     * another mutant. Mutants that share Pest's id leave the same source, so
     * each is paired with the one found on its own line, or else the next.
     */
    public static function matched(Mutants $mutants, Mutants $found): Mutants
    {
        $again = [];
        $matched = [];

        foreach ($found as $mutant) {
            $again[$mutant->nativeId()][] = $mutant;
        }

        foreach ($mutants as $mutant) {
            $native = $mutant->nativeId();
            $left = array_key_exists($native, $again) ? $again[$native] : [];
            $at = self::pairedIn($left, $mutant);
            $matched[] = array_key_exists($at, $left)
                ? $left[$at]->identifiedAs($mutant->id())
                : Interpretation::unjudged($mutant, Reason::that(self::NOT_FOUND_AGAIN));
            unset($again[$native][$at]);
        }

        return Mutants::of(...$matched);
    }

    /**
     * Where among the mutants found again with its Pest id a mutant is: the
     * one on its own line, or else the first; none where none is left.
     *
     * @param array<int, Mutant> $left
     */
    private static function pairedIn(array $left, Mutant $mutant): int
    {
        $line = $mutant->location()->start()->number();
        $same = array_filter($left, static fn(Mutant $found): bool => $found->location()->start()->number() === $line);

        return array_key_first($same) ?? array_key_first($left) ?? -1;
    }
}
