<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_filter;
use function array_flip;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function preg_match;

use stdClass;

/**
 * A ledger as its file holds it: `"format": 2`, compact JSON, gzipped.
 *
 * Writing keeps what {@see LedgerRetention::standard()} keeps: the bases its
 * runs saw most recently, and the proofs established at them. Each mutant
 * that was not killed keeps its full record, and each killed one is
 * `[id, line, mutator]`, the mutator an index into the ledger's `mutators`.
 *
 * Reading keeps each well-formed entry and drops anything else, never
 * repairing it, so an unreadable ledger, one of another format among them,
 * costs a run and never a verdict.
 *
 * @internal the shape of the ledger file
 *
 * @phpstan-type KilledRecord array{string, int, int}
 * @phpstan-type FullRecord array<string, int|float|string>
 * @phpstan-type ProofRecord array{
 *     unit: string,
 *     base: string,
 *     at: string,
 *     run: string,
 *     mutants: list<KilledRecord|FullRecord>,
 * }
 * @phpstan-type TimingRecord array{seconds: float, runner: string, at: string}
 */
final readonly class LedgerFile
{
    private const int FORMAT = 2;

    /** What a message calls the file. */
    private const string NAMED = 'The ledger';

    /** A content key or a base: a SHA-256, in lowercase hex. */
    private const string DIGEST = '/^[0-9a-f]{64}$/D';

    private const string SECONDS = 'seconds';

    private const string RUNNER = 'runner';

    private const string AT = 'at';

    private const string BASE = 'base';

    private const string BASES = 'bases';

    private const string MUTATORS = 'mutators';

    private const string PASSED = 'passed';

    private const string MUTANTS = 'mutants';

    public static function encode(Ledger $ledger): string
    {
        $retention = LedgerRetention::standard();
        $kept = $retention->proofsOf($ledger);
        $mutators = self::mutatorsOf($kept);
        $proofs = [];

        foreach ($kept as $proof) {
            $proofs[$proof->key()->value()] = self::proof($proof, array_flip($mutators));
        }

        $timings = self::timings($ledger->timings());
        $passed = $ledger->lastPassed();

        return Gzip::pack(Json::compact([
            'format' => self::FORMAT,
            self::BASES => array_map(
                static fn(Digest $base): string => $base->value(),
                [...$retention->basesOf($ledger)],
            ),
            self::MUTATORS => $mutators,
            'proofs' => $proofs === [] ? new stdClass() : $proofs,
            'timings' => $timings === [] ? new stdClass() : $timings,
            ...$passed instanceof Passed ? [self::PASSED => [
                'commit' => $passed->commit()->name(),
                'check' => $passed->check(),
                'ownScopeProofs' => $passed->ownScopeProofs(),
            ]] : [],
        ]));
    }

    public static function decode(string $bytes): Ledger
    {
        $json = Gzip::unpack($bytes, self::NAMED);
        $file = Node::decode($json instanceof CannotJudge ? '' : $json);

        if (! self::isThisFormat($file)) {
            return Ledger::empty();
        }

        $mutators = self::mutatorsIn($file);
        $proofs = [];
        $timings = [];

        foreach (self::entriesOf($file->field('proofs')) as $key => $entry) {
            $proofs[] = self::proofsIn($key, $entry, $mutators);
        }

        foreach (self::entriesOf($file->field('timings')) as $unit => $entry) {
            $timings[] = self::timingsIn($unit, $entry);
        }

        return self::passedIn($file)
            ->withBases(self::basesIn($file))
            ->withProofs(Proofs::of(...array_merge(...$proofs)))
            ->withTimings(Timings::of(...array_merge(...$timings)));
    }

    /**
     * The name of every mutator of a killed mutant of these proofs, each
     * once, in the order they first appear.
     *
     * @param  list<Proof>  $proofs
     * @return list<string>
     */
    private static function mutatorsOf(array $proofs): array
    {
        $named = [];

        foreach ($proofs as $proof) {
            $named[] = array_map(
                static fn(Mutant $mutant): string => $mutant->mutation()->mutator(),
                array_filter(
                    [...$proof->mutants()],
                    static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Killed,
                ),
            );
        }

        return array_values(array_unique(array_merge(...$named)));
    }

    /**
     * @param  array<string, int> $mutators each mutator's index, by its name
     * @return ProofRecord
     */
    private static function proof(Proof $proof, array $mutators): array
    {
        return [
            'unit' => $proof->unit()->value(),
            self::BASE => $proof->run()->base()->value(),
            self::AT => $proof->run()->at()->value(),
            'run' => $proof->run()->id(),
            self::MUTANTS => array_map(
                static fn(Mutant $mutant): array => $mutant->status() === MutantStatus::Killed
                    ? MutantRecord::killed($mutant, $mutators[$mutant->mutation()->mutator()])
                    : MutantRecord::full($mutant),
                [...$proof->mutants()],
            ),
        ];
    }

    /** @return array<string, TimingRecord> */
    private static function timings(Timings $timings): array
    {
        $written = [];

        foreach ($timings as $timing) {
            $written[$timing->unit()->value()] = [
                self::SECONDS => $timing->seconds()->seconds(),
                self::RUNNER => $timing->runner(),
                self::AT => $timing->at()->value(),
            ];
        }

        return $written;
    }

    private static function isThisFormat(Node $file): bool
    {
        try {
            return $file->field('format')->integer() === self::FORMAT;
        } catch (NotInShape) {
            return false;
        }
    }

    private static function passedIn(Node $file): Ledger
    {
        try {
            $passed = $file->field(self::PASSED);
            $own = $passed->field('ownScopeProofs')->integer();

            return Ledger::empty()->withPassed(Passed::of(
                Revision::ref($passed->field('commit')->text()),
                $passed->field('check')->text(),
                $own >= 0 ? $own : throw NotInShape::at($passed->field('ownScopeProofs')->at(), 'a count'),
            ));
        } catch (NotInShape) {
            return Ledger::empty();
        }
    }

    /** The bases a ledger holds, the most recently seen first; one that is no digest is dropped. */
    private static function basesIn(Node $file): Bases
    {
        $bases = [];

        foreach (self::itemsOf($file->field(self::BASES)) as $base) {
            $bases[] = self::basesAt($base);
        }

        return Bases::of(...array_merge(...$bases));
    }

    /**
     * The mutator names a ledger's killed mutants point into, each at its
     * index; none where any is not a name, since an index past it would then
     * point at the wrong one.
     *
     * @return list<string>
     */
    private static function mutatorsIn(Node $file): array
    {
        try {
            return array_map(
                static fn(Node $mutator): string => $mutator->text(),
                $file->field(self::MUTATORS)->items(),
            );
        } catch (NotInShape) {
            return [];
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

    /** @return array<string, Node> */
    private static function entriesOf(Node $map): array
    {
        try {
            return $map->entries();
        } catch (NotInShape) {
            return [];
        }
    }

    /** @return list<Digest> the base a place holds, or none where it holds none */
    private static function basesAt(Node $base): array
    {
        try {
            return [self::digestIn($base, 'a base')];
        } catch (NotInShape) {
            return [];
        }
    }

    /** @throws NotInShape */
    private static function digestIn(Node $digest, string $expected): Digest
    {
        return preg_match(self::DIGEST, $digest->text()) === 1
            ? Digest::of($digest->text())
            : throw NotInShape::at($digest->at(), $expected);
    }

    /**
     * @param  list<string> $mutators
     * @return list<Proof>  the proof an entry holds, or none where it is malformed
     */
    private static function proofsIn(string $key, Node $entry, array $mutators): array
    {
        try {
            return preg_match(self::DIGEST, $key) === 1 ? [self::proofIn(Digest::of($key), $entry, $mutators)] : [];
        } catch (NotInShape) {
            return [];
        }
    }

    /**
     * @param list<string> $mutators
     *
     * @throws NotInShape
     */
    private static function proofIn(Digest $key, Node $entry, array $mutators): Proof
    {
        $unit = Path::of($entry->field('unit')->text());
        $mutants = [];

        foreach ($entry->field(self::MUTANTS)->items() as $record) {
            $mutants[] = MutantRecord::isFull($record)
                ? self::notKilledIn($record)
                : MutantRecord::readKilled($record, $unit, $mutators);
        }

        return Proof::of(
            $key,
            $unit,
            Mutants::of(...$mutants),
            Run::of(
                $entry->field('run')->text(),
                self::instantIn($entry),
                self::digestIn($entry->field(self::BASE), 'a base'),
            ),
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
            ? throw NotInShape::at($record->at(), 'a killed mutant, as [id, line, mutator]')
            : $mutant;
    }

    /** @return list<Timing> the timing an entry holds, or none where it is malformed */
    private static function timingsIn(string $unit, Node $entry): array
    {
        try {
            return [Timing::of(
                Path::of($unit),
                self::secondsIn($entry),
                $entry->field(self::RUNNER)->text(),
                self::instantIn($entry),
            )];
        } catch (NotInShape) {
            return [];
        }
    }

    /** @throws NotInShape */
    private static function secondsIn(Node $entry): Seconds
    {
        $seconds = $entry->field(self::SECONDS)->number();

        return $seconds >= 0.0
            ? Seconds::of($seconds)
            : throw NotInShape::at($entry->field(self::SECONDS)->at(), 'a duration');
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
