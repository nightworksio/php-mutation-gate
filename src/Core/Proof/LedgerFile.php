<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_first;
use function array_map;
use function array_merge;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Mutant\KilledRecords;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A ledger as its file holds it: `"format": 3`, compact JSON, gzipped. A
 * ledger of format 2 reads as it is: its proofs record no digests of their
 * inputs, so none of them carries for a unit a budget ran out before.
 *
 * Writing keeps what {@see LedgerRetention::standard()} keeps: the bases its
 * runs saw most recently, the proofs established at them, and the kill
 * history of their mutants and of the most recent functions; and no more
 * than the limits a run reads to ({@see EncodedLedger}). Each mutant
 * that was not killed keeps its full record, and each killed one is
 * `[id, line, mutator, killers]`, the mutator an index into the ledger's
 * `mutators` and the killers indices into its `tests`. The `killers` section
 * points into `tests` too ({@see KillersRecord}), and each proof's digests
 * point into its `inputs` ({@see InputsTable}).
 *
 * Reading keeps each well-formed entry and drops anything else, never
 * repairing it, so an unreadable ledger, one of another format among them,
 * costs a run and never a verdict.
 *
 * @internal the shape of the ledger file
 *
 * @phpstan-type TimingRecord array{seconds: float, runner: string, at: string}
 */
final readonly class LedgerFile
{
    /** The ledger's name, in whatever directory or bucket keeps it. */
    public const string NAME = 'ledger.json.gz';

    /** The list a ledger's killed mutants and its kill history point into by index. */
    public const string TESTS = 'tests';

    public const int FORMAT = 3;

    public const string SECONDS = 'seconds';

    public const string RUNNER = 'runner';

    /** Where a timing names the gate that measured it, as its version spells it. */
    public const string GATE = 'measuredBy';

    public const string AT = ProofRecord::AT;

    public const string BASES = 'bases';

    public const string MUTATORS = 'mutators';

    public const string PASSED = 'passed';

    public const string PROOFS = 'proofs';

    /** The format before proofs recorded the digests of their inputs, which reads without them. */
    private const int UNDIGESTED = 2;

    /** What a message calls the file. */
    private const string NAMED = 'The ledger';

    private const string OTHER_FORMAT = 'The ledger is of a format this gate does not read.';


    /** A ledger's file, within the limits a run reads to: {@see EncodedLedger::within()}. */
    public static function encode(Ledger $ledger): string
    {
        return EncodedLedger::within($ledger, LedgerLimits::standard())->bytes();
    }

    /** The ledger these bytes hold; empty where they hold none this gate reads. */
    public static function decode(string $bytes): Ledger
    {
        $read = self::read($bytes, LedgerLimits::standard());

        return $read instanceof Ledger ? $read : Ledger::empty();
    }

    /**
     * The ledger these bytes hold, within the limits; or why they hold none: they are past the limit, compressed
     * or decompressed, no whole gzip stream, or of another format. Past the compressed limit, none is inflated.
     * Each proof's entry is let go once its proof is read, so the decoded file and the ledger read from it are
     * not both held whole.
     */
    public static function read(string $bytes, LedgerLimits $limits): Ledger|CannotJudge|TooLarge
    {
        $json = $limits->inflated($bytes, self::NAMED);

        if (! is_string($json)) {
            return $json;
        }

        $file = Node::decode($json);
        unset($json);

        if (! self::isReadable($file)) {
            return CannotJudge::because(self::OTHER_FORMAT);
        }

        $ledger = self::ledgerIn($file);
        $read = self::proofsRead($file);
        $entries = self::entriesOf($file->field(self::PROOFS));
        unset($file);
        $proofs = [];

        while (($key = array_key_first($entries)) !== null) {
            $proofs[] = self::proofsIn(sprintf('%s', $key), $entries[$key], $read);
            unset($entries[$key]);
        }

        return $ledger->withProofs(Proofs::of(...array_merge(...$proofs)));
    }

    /** What a file's proofs point into and share. */
    private static function proofsRead(Node $file): ProofsRead
    {
        return ProofsRead::of(
            KilledRecords::of(self::namesIn($file, self::MUTATORS), self::namesIn($file, self::TESTS)),
            InputsTable::read($file->field(InputsTable::SECTION)),
        );
    }

    /** What a file holds besides its proofs. */
    private static function ledgerIn(Node $file): Ledger
    {
        $timings = [];

        foreach (self::entriesOf($file->field('timings')) as $unit => $entry) {
            $timings[] = self::timingsIn(sprintf('%s', $unit), $entry);
        }

        $runs = self::passedIn($file);
        $lastRun = LastRunRecord::read($file->field(LastRunRecord::SECTION));

        return Ledger::empty()
            ->withRuns($lastRun instanceof LastRun ? $runs->lastRunAt($lastRun) : $runs)
            ->withBases(self::basesIn($file))
            ->withTimings(Timings::of(...array_merge(...$timings)))
            ->withLearned(KillersRecord::read($file->field(KillersRecord::SECTION), self::namesIn($file, self::TESTS)))
            ->withLearned(AnalysersRecord::read($file->field(AnalysersRecord::SECTION)))
            ->withLearned(SurvivalRecord::read($file->field(SurvivalRecord::SECTION)));
    }

    private static function isReadable(Node $file): bool
    {
        try {
            $format = $file->field('format')->integer();

            return $format === self::FORMAT || $format === self::UNDIGESTED;
        } catch (NotInShape) {
            return false;
        }
    }

    private static function passedIn(Node $file): ScopeRuns
    {
        try {
            return ScopeRuns::none()->passing(PassedRecord::read($file->field(self::PASSED)));
        } catch (NotInShape) {
            return ScopeRuns::none();
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
     * A list a ledger's killed mutants point into, its mutator names or its
     * test ids, each at its index; none where any is not text, since an index
     * past it would then point at the wrong one.
     *
     * @return list<string>
     */
    private static function namesIn(Node $file, string $list): array
    {
        try {
            return array_map(static fn(Node $name): string => $name->text(), $file->field($list)->items());
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

    /** @return array<array-key, Node> each entry, by its key, which PHP keys as a number where it reads as one */
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
            return Digest::isSha256($base->text()) ? [Digest::of($base->text())] : [];
        } catch (NotInShape) {
            return [];
        }
    }


    /** @return list<Proof> the proof an entry holds, or none where it is malformed */
    private static function proofsIn(string $key, Node $entry, ProofsRead $read): array
    {
        try {
            return Digest::isSha256($key)
                ? [ProofRecord::read(Digest::of($key), $entry, $read)]
                : [];
        } catch (NotInShape) {
            return [];
        }
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
            )->measuredBy(
                $entry->field(self::GATE)->isPresent()
                    ? GateRelease::spelt($entry->field(self::GATE)->text())
                    : GateRelease::unrecorded(),
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
