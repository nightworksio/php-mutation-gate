<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_values;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * Whether a unit's fresh result agrees with a proof already under its key
 * (ADR-0007, decision 3; ADR-0008, decision 3). The same key means the same
 * code, so where the two differ, the mutants that differ are flaky, and
 * neither result is used: the fresh one is not recorded, and the proof goes.
 */
final readonly class Agreement
{
    /** Each result, with the mutants a proof under its key disagrees on marked flaky. */
    public static function checked(UnitResults $fresh, Keys $keys, Proofs ...$ledgers): UnitResults
    {
        $checked = [];

        foreach ($fresh as $result) {
            $key = $keys->keyOf($result->unit()->path());
            $checked[] = $key instanceof Digest ? self::against($result, $key, array_values($ledgers)) : $result;
        }

        return UnitResults::of(...$checked);
    }

    /** @param list<Proofs> $ledgers */
    private static function against(UnitResult $result, Digest $key, array $ledgers): UnitResult
    {
        $flaky = $result->flaky();

        foreach ($ledgers as $proofs) {
            $proof = $proofs->proofFor($key);
            $flaky = $proof instanceof Proof
                ? $flaky->and($proof->disagreeingWith($result->mutants()))
                : $flaky;
        }

        return $result->withFlaky($flaky);
    }
}
