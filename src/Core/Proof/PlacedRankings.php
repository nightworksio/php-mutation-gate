<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;
use function array_merge;
use function count;
use function is_int;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\RankedMutant;
use NightWorksIO\MutationGate\Core\Order\Ranking;

/**
 * The mutant rankings a ledger's `killers` section places among its proofs'
 * records, each `[proof, place, pairs]`: the proof's index among the
 * ledger's proofs, the mutant's among that proof's records, and its ranking
 * as {@see KillersRecord} writes one. Reading keeps each whose proof and
 * place name a mutant and whose ranking keeps a pair, and drops any other.
 *
 * @internal the shape of the `killers` section of the ledger file
 */
final readonly class PlacedRankings
{
    /** How many fields a placed ranking holds: its proof, its place and its pairs. */
    private const int FIELDS = 3;

    /**
     * The rankings of a history's mutants that one of a ledger's proofs records, each where it records it, the
     * newest last.
     *
     * @param  array<string, int>                   $tests  each test's index in the ledger, by its id
     * @param  array<string, array{int, int}>       $places each mutant's proof and place among its records, by id
     * @return list<array{int, int, list<array{int, int}>}>
     */
    public static function of(KillHistory $history, array $tests, array $places): array
    {
        $placed = [];

        foreach ($history->mutants() as $ranked) {
            $id = $ranked->mutant()->value();

            if (array_key_exists($id, $places)) {
                $placed[] = [...$places[$id], KillersRecord::pairs($ranked->ranking(), $tests)];
            }
        }

        return $placed;
    }

    /**
     * The rankings of a history's mutants that no proof of the ledger records, by each mutant's id.
     *
     * @param  array<string, int>                     $tests  each test's index in the ledger, by its id
     * @param  array<string, array{int, int}>         $places each mutant's proof and place among its records, by id
     * @return array<string, list<array{int, int}>>
     */
    public static function unplaced(KillHistory $history, array $tests, array $places): array
    {
        $unplaced = [];

        foreach ($history->mutants() as $ranked) {
            $id = $ranked->mutant()->value();

            if (array_key_exists($id, $places)) {
                continue;
            }

            $unplaced[$id] = KillersRecord::pairs($ranked->ranking(), $tests);
        }

        return $unplaced;
    }

    /**
     * The proofs, by their index among a ledger's proofs, that placed rankings point into.
     *
     * @return array<int, true>
     */
    public static function proofsIn(Node $placed): array
    {
        $proofs = [];

        foreach (self::itemsOf($placed) as $ranking) {
            $fields = self::itemsOf($ranking);
            $proof = $fields === [] ? NotGiven::value() : self::indexIn($fields[0]);

            if (is_int($proof)) {
                $proofs[$proof] = true;
            }
        }

        return $proofs;
    }

    /**
     * Each placed ranking whose proof and place name a mutant, with the mutant they name.
     *
     * @param  list<string>             $tests  the ledger's test ids, each at its index
     * @param  array<int, list<string>> $proofs the ids each proof records, in their order, by the proof's index
     * @return list<RankedMutant>
     */
    public static function read(Node $placed, array $tests, array $proofs): array
    {
        $ranked = [];

        foreach (self::itemsOf($placed) as $ranking) {
            $ranked[] = self::rankedIn(self::itemsOf($ranking), $tests, $proofs);
        }

        return array_merge(...$ranked);
    }

    /**
     * @param  list<Node>               $fields
     * @param  list<string>             $tests
     * @param  array<int, list<string>> $proofs
     * @return list<RankedMutant>       the ranking the fields hold, or none where they name no mutant or no pair
     */
    private static function rankedIn(array $fields, array $tests, array $proofs): array
    {
        $mutant = count($fields) === self::FIELDS ? self::mutantAt($fields[0], $fields[1], $proofs) : NotGiven::value();
        $ranking = $mutant instanceof MutantId ? KillersRecord::rankingIn($fields[2], $tests) : Ranking::none();

        return $mutant instanceof MutantId && count($ranking) > 0 ? [RankedMutant::of($mutant, $ranking)] : [];
    }

    /** @param array<int, list<string>> $proofs */
    private static function mutantAt(Node $proof, Node $place, array $proofs): MutantId|NotGiven
    {
        $index = self::indexIn($proof);
        $at = self::indexIn($place);
        $records = is_int($index) && array_key_exists($index, $proofs) ? $proofs[$index] : [];
        $id = is_int($at) && array_key_exists($at, $records) ? MutantId::parse($records[$at]) : NotGiven::value();

        return $id instanceof MutantId ? $id : NotGiven::value();
    }

    private static function indexIn(Node $index): int|NotGiven
    {
        try {
            return $index->integer();
        } catch (NotInShape) {
            return NotGiven::value();
        }
    }

    /** @return list<Node> */
    private static function itemsOf(Node $list): array
    {
        try {
            return $list->items();
        } catch (NotInShape) {
            return [];
        }
    }
}
