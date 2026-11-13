<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ProofEntry;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\TakenProofs;
use NightWorksIO\MutationGate\Core\Serve\KeepsProofs;

/**
 * The proofs `outbreak serve` keeps under the keys a run proves its units by
 * and no ledger it read holds (ADR-0133): those `watch` rounds kept since a
 * ledger was last written. A run takes them only where it takes every proof,
 * its own scope's too, and each only under the key it is asked by; where no
 * daemon serves the project, or it answers none, the run proves from its
 * ledgers alone.
 *
 * @internal
 */
final readonly class ServedProofs
{
    /** The daemon that keeps the proofs, where `outbreak serve` may serve the project; none otherwise. */
    public static function state(Adapters $adapters): KeepsProofs|NotGiven
    {
        $engine = $adapters->maps;

        return $engine instanceof Outbreak ? $engine->served() : NotGiven::value();
    }

    public static function read(Adapters $adapters, TakenProofs $taken, Ledgers $ledgers, Keys $keys): Proofs
    {
        $state = self::state($adapters);

        if (! $state instanceof KeepsProofs || $taken !== TakenProofs::Every) {
            return Proofs::none();
        }

        $sought = self::unheld($ledgers, $keys);
        $answered = $sought === [] ? [] : $state->proofs(...$sought);

        return $answered instanceof NotWritten ? Proofs::none() : self::proofsIn($answered);
    }

    /** @return list<string> each key no ledger read holds a proof under */
    private static function unheld(Ledgers $ledgers, Keys $keys): array
    {
        $sought = [];

        foreach ($keys->units() as $unit) {
            $key = $keys->keyOf($unit);

            if (
                $key instanceof Digest
                && ! $ledgers->defaultBranch()->proofs()->has($key)
                && ! $ledgers->own()->proofs()->has($key)
            ) {
                $sought[] = $key->value();
            }
        }

        return $sought;
    }

    /** @param array<string, string> $answered each proof's entry, by its key */
    private static function proofsIn(array $answered): Proofs
    {
        $proofs = Proofs::none();

        foreach ($answered as $key => $entry) {
            $proof = ProofEntry::read(Digest::of($key), $entry);
            $proofs = $proof instanceof Proof ? $proofs->with($proof) : $proofs;
        }

        return $proofs;
    }
}
