<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_map;

use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/**
 * Mutants a run made again, each matched to the mutant asked for by Pest's
 * id and handed back under the gate's id its first run gave it. A run that
 * makes only some of a file's mutants numbers those that share a change among
 * themselves, so its own gate ids can name another mutant. Mutants that share
 * Pest's id leave the same source, so each is paired with the one found on
 * its own line; one found on no line of its own is unjudged, rather than take
 * another's result, and the evidence of each kill found again goes with it.
 */
final readonly class FoundAgain
{
    /** Why a mutant run again alone is unjudged: Pest made no mutant with its id. */
    public const string NOT_FOUND_AGAIN = 'Run again alone, Pest made no mutant with this id.';

    public static function among(Mutants $asked, Mutants $found, Reason $unmade): Mutants
    {
        $matched = [];

        foreach (self::paired($asked, $found) as [$mutant, $again]) {
            $matched[] = $again instanceof Mutant
                ? $again->identifiedAs($mutant->id())
                : Interpretation::unjudged($mutant, $unmade);
        }

        return Mutants::of(...$matched);
    }

    /**
     * The evidence of the kills a run made again, each under the gate's id of
     * the mutant asked for that it is matched to.
     */
    public static function evidenceAmong(Mutants $asked, MutationResult $found): Evidences
    {
        $evidence = Evidences::none();

        foreach (self::paired($asked, $found->mutants()) as [$mutant, $again]) {
            if ($again instanceof Mutant) {
                $evidence = $evidence->with($mutant->id(), $found->evidence()->of($again->id()));
            }
        }

        return $evidence;
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
     * Each mutant asked for, and the one found again it is matched to, or
     * none where none is.
     *
     * @return list<array{Mutant, Mutant|null}>
     */
    private static function paired(Mutants $asked, Mutants $found): array
    {
        $again = [];
        $paired = [];

        foreach ($found as $mutant) {
            $again[$mutant->nativeId()][] = $mutant;
        }

        foreach ($asked as $mutant) {
            $native = $mutant->nativeId();
            $left = array_key_exists($native, $again) ? $again[$native] : [];
            $at = self::onItsLine($left, $mutant);
            $paired[] = array_key_exists($at, $left) ? [$mutant, $left[$at]] : [$mutant, null];
            unset($again[$native][$at]);
        }

        return $paired;
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
