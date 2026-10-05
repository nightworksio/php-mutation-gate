<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_map;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Reason;

/**
 * Mutants a run made again, each matched to the mutant asked for by Pest's
 * id and handed back under the gate's id its first run gave it. A run that
 * makes only some of a file's mutants numbers those that share a change among
 * themselves, so its own gate ids can name another mutant. Mutants that share
 * Pest's id leave the same source, so each is paired with the one found on
 * its own line; one found on no line of its own is unjudged, rather than take
 * another's result.
 */
final readonly class FoundAgain
{
    /** Why a mutant run again alone is unjudged: Pest made no mutant with its id. */
    public const string NOT_FOUND_AGAIN = 'Run again alone, Pest made no mutant with this id.';

    public static function among(Mutants $asked, Mutants $found, Reason $unmade): Mutants
    {
        $again = [];
        $matched = [];

        foreach ($found as $mutant) {
            $again[$mutant->nativeId()][] = $mutant;
        }

        foreach ($asked as $mutant) {
            $native = $mutant->nativeId();
            $left = array_key_exists($native, $again) ? $again[$native] : [];
            $at = self::onItsLine($left, $mutant);
            $matched[] = array_key_exists($at, $left)
                ? $left[$at]->identifiedAs($mutant->id())
                : Interpretation::unjudged($mutant, $unmade);
            unset($again[$native][$at]);
        }

        return Mutants::of(...$matched);
    }

    /** The mutants, each one found again replaced by what that run reported of it. */
    public static function replacing(Mutants $mutants, Mutants $again): Mutants
    {
        $by = [];

        foreach ($again as $mutant) {
            $by[$mutant->id()->key()] = $mutant;
        }

        return Mutants::of(...array_map(
            static fn(Mutant $mutant): Mutant => array_key_exists($mutant->id()->key(), $by)
                ? $by[$mutant->id()->key()]
                : $mutant,
            [...$mutants],
        ));
    }

    /**
     * Where among the mutants found again with its Pest id a mutant is: the
     * first left on its own line; none where none is.
     *
     * @param array<int, Mutant> $left
     */
    private static function onItsLine(array $left, Mutant $mutant): int
    {
        $line = $mutant->location()->start()->number();
        $same = array_filter($left, static fn(Mutant $found): bool => $found->location()->start()->number() === $line);

        return array_key_first($same) ?? -1;
    }
}
