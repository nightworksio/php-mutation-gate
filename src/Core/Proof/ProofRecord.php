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
use NightWorksIO\MutationGate\Core\Time\Instant;

use function preg_match;

/**
 * A proof as a ledger holds it, under its key: its unit, the base, the time
 * and the id of the run that established it, and its mutants, each killed one
 * as a killed record and every other in full.
 *
 * @internal the shape of a proof in the ledger file
 *
 * @phpstan-type Written array{
 *     unit: string,
 *     base: string,
 *     at: string,
 *     run: string,
 *     mutants: list<array{string, int, int, list<int>}|array<string, int|float|string|list<string>>>,
 * }
 */
final readonly class ProofRecord
{
    /** A base: a SHA-256, in lowercase hex. */
    private const string DIGEST = '/^[0-9a-f]{64}$/D';

    private const string BASE = 'base';

    private const string AT = 'at';

    /**
     * @param  array<string, int> $mutators each mutator's index in the ledger, by its name
     * @param  array<string, int> $tests    each killing test's index in the ledger, by its id
     * @return Written
     */
    public static function of(Proof $proof, array $mutators, array $tests): array
    {
        return [
            'unit' => $proof->unit()->value(),
            self::BASE => $proof->run()->base()->value(),
            self::AT => $proof->run()->at()->value(),
            'run' => $proof->run()->id(),
            'mutants' => array_map(
                static fn(Mutant $mutant): array => $mutant->status() === MutantStatus::Killed
                    ? MutantRecord::killed($mutant, $mutators, $tests)
                    : MutantRecord::full($mutant),
                [...$proof->mutants()],
            ),
        ];
    }

    /**
     * @param list<string> $mutators the ledger's mutator names, each at its index
     * @param list<string> $tests    the ledger's test ids, each at its index
     *
     * @throws NotInShape
     */
    public static function read(Digest $key, Node $entry, array $mutators, array $tests): Proof
    {
        $unit = Path::of($entry->field('unit')->text());
        $mutants = [];

        foreach ($entry->field('mutants')->items() as $record) {
            $mutants[] = MutantRecord::isFull($record)
                ? self::notKilledIn($record)
                : MutantRecord::readKilled($record, $unit, $mutators, $tests);
        }

        return Proof::of(
            $key,
            $unit,
            Mutants::of(...$mutants),
            Run::of($entry->field('run')->text(), self::instantIn($entry), self::baseIn($entry)),
        );
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

        return preg_match(self::DIGEST, $base->text()) === 1
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
