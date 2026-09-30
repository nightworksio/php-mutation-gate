<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * A proof as a ledger holds it, under its key: its unit, the base, the time
 * and the id of the run that established it, its mutants, each killed one as
 * a killed record and every other in full, and the digests of its inputs,
 * where it records them.
 *
 * @internal the shape of a proof in the ledger file
 *
 * @phpstan-import-type ProofWritten from DigestsRecord as ProofDigests
 *
 * @phpstan-type Written array{
 *     unit: string,
 *     base: string,
 *     at: string,
 *     run: string,
 *     mutants: list<array{string, int, int, list<int>}|array<string, int|float|string|list<string>>>,
 *     digests?: ProofDigests,
 * }
 */
final readonly class ProofRecord
{
    /** When a run was, in the ledger's proofs and its timings alike. */
    public const string AT = 'at';
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
        ];
    }

    /**
     * @param list<string> $mutators the ledger's mutator names, each at its index
     * @param list<string> $tests    the ledger's test ids, each at its index
     * @param InputsTable  $inputs   what the digests of the ledger's proofs share
     *
     * @throws NotInShape
     */
    public static function read(Digest $key, Node $entry, array $mutators, array $tests, InputsTable $inputs): Proof
    {
        $unit = Path::of($entry->field('unit')->text());
        $reported = [];
        $kills = [];

        foreach ($entry->field('mutants')->items() as $record) {
            if (MutantRecord::isFull($record)) {
                $reported[] = self::notKilledIn($record);

                continue;
            }

            $kills[] = MutantRecord::readKilled($record, $unit, $mutators, $tests);
        }

        $digests = $entry->field(DigestsRecord::FIELD);

        return Proof::held(
            $key,
            $unit,
            Mutants::of(...$reported),
            ProvedKills::of(...$kills),
            Run::of($entry->field('run')->text(), self::instantIn($entry), self::baseIn($entry)),
        )->withInputs($digests->isPresent() ? DigestsRecord::readProof($digests, $inputs) : Undigested::proof());
    }

    /** @return array{digests?: ProofDigests} the digests of the proof's inputs, where it records them */
    private static function digests(Inputs|Undigested $inputs, InputsTable $table): array
    {
        return $inputs instanceof Inputs ? [DigestsRecord::FIELD => DigestsRecord::ofProof($inputs, $table)] : [];
    }

    /**
     * A mutant's full record, which the ledger keeps of every mutant that was not killed.
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
