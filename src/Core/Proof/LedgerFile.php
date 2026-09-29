<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
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
 * A ledger as its file holds it, `"format": 1`. Reading keeps each
 * well-formed entry and drops anything else, never repairing it, so an
 * unreadable ledger costs a run and never a verdict. Writing keeps the newest
 * {@see KEPT} proofs, each mutant that was not killed with its full record and
 * each killed one with its id, line and status.
 *
 * @internal the shape of the ledger file
 */
final readonly class LedgerFile
{
    /** How many proofs a ledger keeps, the newest; older ones are of code long since changed. */
    public const int KEPT = 20_000;

    private const int FORMAT = 1;

    /** A content key: a SHA-256, in lowercase hex. */
    private const string KEY = '/^[0-9a-f]{64}$/D';

    private const string SECONDS = 'seconds';

    private const string RUNNER = 'runner';

    private const string AT = 'at';

    private const string PASSED = 'passed';

    private const string MUTANTS = 'mutants';

    public static function encode(Ledger $ledger): string
    {
        $proofs = [];
        $timings = [];

        foreach ($ledger->proofs()->newest(self::KEPT) as $proof) {
            $proofs[$proof->key()->value()] = self::proof($proof);
        }

        foreach ($ledger->timings() as $timing) {
            $timings[$timing->unit()->value()] = [
                self::SECONDS => $timing->seconds()->seconds(),
                self::RUNNER => $timing->runner(),
                self::AT => $timing->at()->value(),
            ];
        }

        $passed = $ledger->lastPassed();

        return Json::encode([
            'format' => self::FORMAT,
            'proofs' => $proofs === [] ? new stdClass() : $proofs,
            'timings' => $timings === [] ? new stdClass() : $timings,
            ...$passed instanceof Revision ? [self::PASSED => $passed->name()] : [],
        ]);
    }

    public static function decode(string $json): Ledger
    {
        $file = Node::decode($json);

        if (! self::isThisFormat($file)) {
            return Ledger::empty();
        }

        $ledger = self::passedIn($file);

        foreach (self::entriesOf($file->field('proofs')) as $key => $entry) {
            $ledger = self::withProofIn($ledger, $key, $entry);
        }

        foreach (self::entriesOf($file->field('timings')) as $unit => $entry) {
            $ledger = self::withTimingIn($ledger, $unit, $entry);
        }

        return $ledger;
    }

    /** @return array<string, mixed> */
    private static function proof(Proof $proof): array
    {
        return [
            'unit' => $proof->unit()->value(),
            self::AT => $proof->run()->at()->value(),
            'run' => $proof->run()->id(),
            self::MUTANTS => array_map(
                static fn(Mutant $mutant): array => $mutant->status() === MutantStatus::Killed
                    ? MutantRecord::brief($mutant)
                    : MutantRecord::full($mutant),
                iterator_to_array($proof->mutants(), preserve_keys: false),
            ),
        ];
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
            return Ledger::empty()->withPassed(Revision::ref($file->field(self::PASSED)->text()));
        } catch (NotInShape) {
            return Ledger::empty();
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

    private static function withProofIn(Ledger $ledger, string $key, Node $entry): Ledger
    {
        try {
            return preg_match(self::KEY, $key) === 1
                ? $ledger->withProof(self::proofIn(Digest::of($key), $entry))
                : $ledger;
        } catch (NotInShape) {
            return $ledger;
        }
    }

    /** @throws NotInShape */
    private static function proofIn(Digest $key, Node $entry): Proof
    {
        $unit = Path::of($entry->field('unit')->text());
        $mutants = Mutants::none();

        foreach ($entry->field(self::MUTANTS)->items() as $record) {
            $mutants = $mutants->with(self::mutantIn($record, $unit));
        }

        return Proof::of($key, $unit, $mutants, Run::of($entry->field('run')->text(), self::instantIn($entry)));
    }

    /**
     * A mutant of a proof: a full record, or the brief one of a killed mutant.
     *
     * @throws NotInShape
     */
    private static function mutantIn(Node $record, Path $unit): Mutant
    {
        if (MutantRecord::isFull($record)) {
            return MutantRecord::readFull($record);
        }

        $mutant = MutantRecord::readBrief($record, $unit);

        return $mutant->status() === MutantStatus::Killed
            ? $mutant
            : throw NotInShape::at($record->at(), 'the full record of a mutant that was not killed');
    }

    private static function withTimingIn(Ledger $ledger, string $unit, Node $entry): Ledger
    {
        try {
            return $ledger->withTiming(Timing::of(
                Path::of($unit),
                self::secondsIn($entry),
                $entry->field(self::RUNNER)->text(),
                self::instantIn($entry),
            ));
        } catch (NotInShape) {
            return $ledger;
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
