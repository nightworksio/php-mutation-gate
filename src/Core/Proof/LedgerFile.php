<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_flip;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use stdClass;

/**
 * A ledger as its file holds it: `"format": 2`, compact JSON, gzipped.
 *
 * Writing keeps what {@see LedgerRetention::standard()} keeps: the bases its
 * runs saw most recently, the proofs established at them, and the kill
 * history of their mutants and of the most recent functions. Each mutant
 * that was not killed keeps its full record, and each killed one is
 * `[id, line, mutator, killers]`, the mutator an index into the ledger's
 * `mutators` and the killers indices into its `tests`. The `killers` section
 * points into `tests` too ({@see KillersRecord}).
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

    private const int FORMAT = 2;

    /** What a message calls the file. */
    private const string NAMED = 'The ledger';

    private const string SECONDS = 'seconds';

    private const string RUNNER = 'runner';

    private const string AT = ProofRecord::AT;

    private const string BASES = 'bases';

    private const string MUTATORS = 'mutators';

    private const string PASSED = 'passed';


    public static function encode(Ledger $ledger): string
    {
        $retention = LedgerRetention::standard();
        $kept = $retention->proofsOf($ledger);
        $killers = $retention->killersOf($ledger);
        $mutators = self::namesOf($kept, static fn(Mutant $killed): array => [$killed->mutation()->mutator()]);
        $tests = array_values(array_unique([
            ...self::namesOf($kept, static fn(Mutant $killed): array => self::idsOf($killed->killers())),
            ...KillersRecord::testsOf($killers),
        ]));
        $proofs = [];

        foreach ($kept as $proof) {
            $proofs[$proof->key()->value()] = ProofRecord::of($proof, array_flip($mutators), array_flip($tests));
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
            self::TESTS => $tests,
            'proofs' => $proofs === [] ? new stdClass() : $proofs,
            'timings' => $timings === [] ? new stdClass() : $timings,
            KillersRecord::SECTION => KillersRecord::of($killers, array_flip($tests)),
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

        $mutators = self::namesIn($file, self::MUTATORS);
        $tests = self::namesIn($file, self::TESTS);
        $proofs = [];
        $timings = [];

        foreach (self::entriesOf($file->field('proofs')) as $key => $entry) {
            $proofs[] = self::proofsIn($key, $entry, $mutators, $tests);
        }

        foreach (self::entriesOf($file->field('timings')) as $unit => $entry) {
            $timings[] = self::timingsIn($unit, $entry);
        }

        return self::passedIn($file)
            ->withBases(self::basesIn($file))
            ->withProofs(Proofs::of(...array_merge(...$proofs)))
            ->withTimings(Timings::of(...array_merge(...$timings)))
            ->withKillers(KillersRecord::read($file->field(KillersRecord::SECTION), $tests));
    }

    /**
     * What the killed mutants of these proofs name, each once, in the order
     * they first name it: their mutators, or the tests that killed them.
     *
     * @param  list<Proof>                   $proofs
     * @param  Closure(Mutant): list<string> $named
     * @return list<string>
     */
    private static function namesOf(array $proofs, Closure $named): array
    {
        $names = [];

        foreach ($proofs as $proof) {
            foreach ($proof->mutants() as $mutant) {
                $names[] = $mutant->status() === MutantStatus::Killed ? $named($mutant) : [];
            }
        }

        return array_values(array_unique(array_merge(...$names)));
    }

    /** @return list<string> */
    private static function idsOf(TestIds $tests): array
    {
        return array_map(static fn(TestId $test): string => $test->value(), [...$tests]);
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
            return Digest::isSha256($base->text()) ? [Digest::of($base->text())] : [];
        } catch (NotInShape) {
            return [];
        }
    }


    /**
     * @param  list<string> $mutators
     * @param  list<string> $tests
     * @return list<Proof>  the proof an entry holds, or none where it is malformed
     */
    private static function proofsIn(string $key, Node $entry, array $mutators, array $tests): array
    {
        try {
            return Digest::isSha256($key)
                ? [ProofRecord::read(Digest::of($key), $entry, $mutators, $tests)]
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
