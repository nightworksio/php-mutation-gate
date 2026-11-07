<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\FormatFault;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Mutant\KilledRecords;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A ledger as its file holds it: `"format": 5`, compact JSON, compressed as
 * its codec says ({@see LedgerCodec}). A proof that records no digests of its
 * inputs carries for no unit a budget ran out before.
 *
 * Writing keeps what {@see LedgerRetention::standard()} keeps: the bases its
 * runs saw most recently, the proofs established at them, and the kill
 * history of their mutants and of the most recent functions; and no more
 * than the limits a run reads to ({@see EncodedLedger}). A proof names its
 * base by its index among the `bases`. Each mutant that was not killed keeps
 * its full record, less the diff and all but the analyser and code of its
 * rejection where static analysis killed it, and each killed one is
 * `[id, line, mutator, killers]`, the mutator an index into the ledger's
 * `mutators` and the killers indices into its `tests` ({@see TestsTable}). The
 * `killers` section points into `tests` too, and places each ranking among
 * the proofs' records ({@see KillersRecord}, {@see PlacedRankings}); each
 * proof's digests point into its `inputs` ({@see InputsTable}). A ledger of
 * format 4, which lists its tests, names its bases by digest and keys every
 * ranking by its mutant's id, reads as well (ADR-0085).
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

    /** The file a passing run of a pull request leaves beside its ledger, holding nothing but that pass. */
    public const string PASSED_FILE = 'passed.ledger.json.gz';

    /** The list a ledger's killed mutants and its kill history point into by index. */
    public const string TESTS = 'tests';

    public const int FORMAT = 5;

    public const string SECONDS = 'seconds';

    public const string RUNNER = 'runner';

    /** Where a timing names the gate that measured it, as its version spells it. */
    public const string GATE = 'measuredBy';

    public const string AT = ProofRecord::AT;

    public const string BASES = 'bases';

    public const string MUTATORS = 'mutators';

    public const string PASSED = 'passed';

    public const string PROOFS = 'proofs';

    /** The earlier format a ledger may still be read in, until each scope's next run writes it anew. */
    private const int EARLIER = self::FORMAT - 1;

    /** What a message calls the file. */
    private const string NAMED = 'The ledger';

    private const string OTHER_FORMAT = 'The ledger is of a format this gate does not read.';


    /** A ledger's file, gzipped, within the limits a run reads to: {@see EncodedLedger::within()}. */
    public static function encode(Ledger $ledger): string
    {
        $encoded = EncodedLedger::within($ledger, LedgerLimits::standard(), LedgerCodec::gzipOnly());

        return $encoded instanceof EncodedLedger ? $encoded->bytes() : throw FormatFault::gzip();
    }

    /** The ledger these gzipped bytes hold; empty where they hold none this gate reads. */
    public static function decode(string $bytes): Ledger
    {
        $read = self::read($bytes, LedgerLimits::standard(), LedgerCodec::gzipOnly());

        return $read instanceof Ledger ? $read : Ledger::empty();
    }

    /**
     * The ledger these bytes hold, within the limits; or why they hold none: they are past the limit, compressed
     * or decompressed, no whole gzip stream, or of another format. Past the compressed limit, none is inflated.
     * Each proof's entry is let go once its proof is read, so the decoded file and the ledger read from it are
     * not both held whole.
     */
    public static function read(string $bytes, LedgerLimits $limits, LedgerCodec $codec): Ledger|CannotJudge|TooLarge
    {
        $json = $limits->admitsPacked(Bytes::length($bytes))
            ? $codec->unpackAtMost($bytes, self::NAMED, $limits->unpacked())
            : TooLarge::because($limits->pastPacked());

        if (! is_string($json)) {
            return $json;
        }

        $file = Node::decode($json);
        unset($json);

        if (! self::isReadable($file)) {
            return CannotJudge::because(self::OTHER_FORMAT);
        }

        $tests = TestsTable::read($file->field(self::TESTS));
        $killers = $file->field(KillersRecord::SECTION);
        $placed = PlacedRankings::proofsIn($killers->field(KillersRecord::PLACED));
        $ledger = self::ledgerIn($file);
        $read = self::proofsRead($file, $tests);
        $entries = self::entriesOf($file->field(self::PROOFS));
        unset($file);
        $proofs = [];
        $records = [];

        foreach (array_keys($entries) as $index => $key) {
            $proofs[] = self::proofsIn(sprintf('%s', $key), $entries[$key], $read);

            if (array_key_exists($index, $placed)) {
                $records[$index] = self::idsIn($entries[$key]);
            }

            unset($entries[$key]);
        }

        return $ledger
            ->withProofs(Proofs::of(...array_merge(...$proofs)))
            ->withLearned(KillersRecord::read($killers, $tests, $records));
    }

    /**
     * What a file's proofs point into and share.
     *
     * @param list<string> $tests the file's test ids, each at its index
     */
    private static function proofsRead(Node $file, array $tests): ProofsRead
    {
        return ProofsRead::of(
            KilledRecords::of(self::namesIn($file, self::MUTATORS), $tests),
            InputsTable::read($file->field(InputsTable::SECTION)),
            self::namesIn($file, self::BASES),
        );
    }

    /** @return list<string> the ids an entry records, in its order; none where it is malformed */
    private static function idsIn(Node $entry): array
    {
        try {
            return ProofRecord::idsIn($entry);
        } catch (NotInShape) {
            return [];
        }
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
            ->withLearned(AnalysersRecord::read($file->field(AnalysersRecord::SECTION)))
            ->withLearned(SurvivalRecord::read($file->field(SurvivalRecord::SECTION)))
            ->withLearned(TallyRecord::read($file->field(TallyRecord::SECTION)))
            ->withLearned(LimitsRecord::read(
                $file->field(LimitsRecord::KILLS),
                $file->field(LimitsRecord::CONCURRENCY),
            ))
            ->withLearned(SubsumptionRecord::read($file->field(SubsumptionRecord::SECTION)));
    }

    private static function isReadable(Node $file): bool
    {
        try {
            $format = $file->field('format')->integer();

            return $format === self::FORMAT || $format === self::EARLIER;
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
