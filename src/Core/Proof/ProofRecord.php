<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Matrix\MatrixRecord;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * A proof as a ledger holds it, under its key: its unit; the base, the time
 * and the id of the run that established it, and `matrix`, `full` where that
 * run recorded every killer; its mutants, each one a test killed as a killed
 * record and every other in full; the digests of its inputs, where it records
 * them; and, for a held unit, the holding tests that run it, as indices into
 * the ledger's tests, where it names any.
 *
 * @internal the shape of a proof in the ledger file
 *
 * @phpstan-import-type ProofWritten from DigestsRecord as ProofDigests
 * @phpstan-import-type Full from MutantRecord
 *
 * @phpstan-type Written array{
 *     unit: string,
 *     base: string,
 *     at: string,
 *     run: string,
 *     matrix?: string,
 *     mutants: list<array{string, int, int, list<int>}|Full>,
 *     digests?: ProofDigests,
 *     judging?: list<int>,
 * }
 */
final readonly class ProofRecord
{
    /** When a run was, in the ledger's proofs and its timings alike. */
    public const string AT = 'at';

    /**
     * The holding tests that run a held unit: in a proof, as indices into the
     * ledger's tests; in a shard result, by id.
     */
    public const string JUDGING = 'judging';
    private const string BASE = 'base';

    /**
     * @param  array<string, int> $mutators each mutator's index in the ledger, by its name
     * @param  array<string, int> $tests    each killing test's index in the ledger, by its id
     * @param  InputsTable        $inputs   what the digests of the ledger's proofs share
     * @return Written
     */
    public static function of(Proof $proof, array $mutators, array $tests, InputsTable $inputs): array
    {
        return [
            'unit' => $proof->unit()->value(),
            self::BASE => $proof->run()->base()->value(),
            self::AT => $proof->run()->at()->value(),
            'run' => $proof->run()->id(),
            ...MatrixRecord::of($proof->run()->matrix()),
            'mutants' => [
                ...array_map(
                    static fn(Mutant $mutant): array => $mutant->status() === MutantStatus::Killed
                        ? MutantRecord::killed($mutant, $mutators, $tests)
                        : MutantRecord::full($mutant),
                    [...$proof->reported()],
                ),
                ...array_map(
                    static fn(ProvedKill $kill): array => MutantRecord::killed($kill, $mutators, $tests),
                    [...$proof->kills()],
                ),
            ],
            ...self::digests($proof->inputs(), $inputs),
            ...self::judging($proof->judging(), $tests),
        ];
    }

    /**
     * A proof under its key, sharing with the ledger's other proofs what they share.
     *
     * @throws NotInShape
     */
    public static function read(Digest $key, Node $entry, ProofsRead $read): Proof
    {
        $unit = $read->unit($entry->field('unit')->text());
        $reported = [];
        $kills = [];

        foreach ($entry->field('mutants')->items() as $record) {
            if (MutantRecord::isFull($record)) {
                $reported[] = self::notKilledIn($record);

                continue;
            }

            $kills[] = MutantRecord::readKilled($record, $unit, $read->killed());
        }

        $digests = $entry->field(DigestsRecord::FIELD);
        $inputs = $digests->isPresent() ? DigestsRecord::readProof($digests, $read->inputs()) : Undigested::proof();

        return Proof::held(
            $key,
            $unit,
            Mutants::of(...$reported),
            ProvedKills::of(...$kills),
            $read->run(
                $entry->field('run')->text(),
                self::instantIn($entry),
                self::baseIn($entry),
                MatrixRecord::read($entry->field(MatrixRecord::FIELD)),
            ),
        )->withInputs($inputs)->judgedBy(self::judgingIn($entry->field(self::JUDGING), $read));
    }

    /**
     * @param  array<string, int>          $tests each listed test's index in the ledger, by its id
     * @return array{judging?: list<int>} the holding tests that run a held unit, where the proof names any
     */
    private static function judging(TestIds $judging, array $tests): array
    {
        $indices = [];

        foreach ($judging as $test) {
            $indices[] = $tests[$test->value()];
        }

        return $indices === [] ? [] : [self::JUDGING => $indices];
    }

    /** @throws NotInShape */
    private static function judgingIn(Node $judging, ProofsRead $read): TestIds
    {
        return $judging->isPresent() ? $read->killed()->killers($judging) : TestIds::none();
    }

    /** @return array{digests?: ProofDigests} the digests of the proof's inputs, where it records them */
    private static function digests(Inputs|Undigested $inputs, InputsTable $table): array
    {
        return $inputs instanceof Inputs ? [DigestsRecord::FIELD => DigestsRecord::ofProof($inputs, $table)] : [];
    }

    /**
     * A mutant's full record, which the ledger keeps of every mutant no test killed.
     *
     * @throws NotInShape
     */
    private static function notKilledIn(Node $record): Mutant
    {
        $mutant = MutantRecord::readFull($record);

        return $mutant->status() === MutantStatus::Killed
            ? throw NotInShape::at($record->at(), 'a killed mutant, as [id, line, mutator, killers]')
            : $mutant;
    }

    /** @throws NotInShape */
    private static function baseIn(Node $entry): Digest
    {
        $base = $entry->field(self::BASE);

        return Digest::isSha256($base->text())
            ? Digest::of($base->text())
            : throw NotInShape::at($base->at(), 'a base');
    }

    /** @throws NotInShape */
    private static function instantIn(Node $entry): Instant
    {
        $instant = Instant::parse($entry->field(self::AT)->text());

        return $instant instanceof CannotJudge
            ? throw NotInShape::at($entry->field(self::AT)->at(), 'an instant')
            : $instant;
    }
}
