<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_map;
use function count;
use function is_string;

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * A mutant as the gate's files write it. The full record holds everything a
 * runner reported of it, with the reason it left one unjudged, what a time
 * budget ran out before where one did, and the tests that killed it, or the
 * static analyser's rejection that did, and, as {@see LimitRecord} writes
 * them, the limit that stopped it and what its unmutated code needs of it.
 * The killed record, which a ledger keeps of a mutant tests killed, is
 * `[id, line, mutator, killers]`, the mutator an index into the ledger's
 * list of mutator names and the killers indices into its list of tests: what
 * ignores and the tests report need of it, in as few bytes as a ledger of
 * many thousands of them can take.
 *
 * @internal the shape of the plan, shard result and ledger files
 *
 * @phpstan-type RejectionWritten array{analyser: string, file: string, code: string, message: string}
 * @phpstan-type Full array{
 *     id: string,
 *     native: string,
 *     file: string,
 *     line: int,
 *     end?: int,
 *     mutator: string,
 *     family: string,
 *     diff: string,
 *     hint?: string,
 *     status: string,
 *     seconds?: float,
 *     limit?: float,
 *     limitBytes?: int,
 *     testSeconds?: float,
 *     suiteBytes?: int,
 *     reason?: string,
 *     outOfTime?: string,
 *     killedBy?: list<string>,
 *     rejection?: RejectionWritten,
 * }
 */
final readonly class MutantRecord
{
    /** The field of a record, and of the JSON report's mutant, that holds the rejection. */
    public const string REJECTION = 'rejection';

    /** A field of a rejection, as {@see self::rejection()} writes it. */
    public const string ANALYSER = 'analyser';

    /** The field of a record that holds the mutant's file, and of a rejection, the file its finding sits in. */
    public const string FILE = 'file';

    /** A field of a rejection, as {@see self::rejection()} writes it. */
    public const string CODE = 'code';

    /** A field of a rejection, as {@see self::rejection()} writes it. */
    public const string MESSAGE = 'message';

    public const string REASON = 'reason';
    private const string ID = 'id';

    private const string LINE = 'line';

    private const string STATUS = 'status';

    private const string END = 'end';

    private const string SECONDS = 'seconds';

    private const string MUTATOR = 'mutator';

    private const string DIFF = 'diff';

    /** A registered mutator's own sentence for a survivor, where it has one. */
    private const string HINT = 'hint';

    /** What a time budget ran out before, where one left the mutant unjudged. */
    private const string OUT_OF_TIME = 'outOfTime';

    /** How many fields a killed record holds: its id, its line, its mutator and its killers. */
    private const int KILLED = 4;

    private const string KILLED_BY = 'killedBy';

    /** @return Full */
    public static function full(Mutant $mutant): array
    {
        $end = $mutant->location()->end();
        $duration = $mutant->duration();
        $reason = $mutant->reason();
        $hint = $mutant->mutation()->hint();

        return [
            self::ID => $mutant->id()->value(),
            'native' => $mutant->nativeId(),
            self::FILE => $mutant->location()->file()->value(),
            self::LINE => $mutant->location()->start()->number(),
            ...$end instanceof Line ? [self::END => $end->number()] : [],
            self::MUTATOR => $mutant->mutation()->mutator(),
            'family' => $mutant->mutation()->family()->value,
            self::DIFF => $mutant->mutation()->diff(),
            ...is_string($hint) ? [self::HINT => $hint] : [],
            self::STATUS => $mutant->status()->value,
            ...$duration instanceof Seconds ? [self::SECONDS => $duration->seconds()] : [],
            ...LimitRecord::of($mutant),
            ...$reason instanceof Reason ? [self::REASON => $reason->text(), ...self::outOfTime($reason)] : [],
            ...count($mutant->killers()) > 0 ? [self::KILLED_BY => self::idsOf($mutant->killers())] : [],
            ...$reason instanceof Rejection ? [self::REJECTION => self::rejection($reason)] : [],
        ];
    }

    /**
     * A rejection as a record and the JSON report write it.
     *
     * @return RejectionWritten
     */
    public static function rejection(Rejection $rejection): array
    {
        return [
            self::ANALYSER => $rejection->analyser(),
            self::FILE => $rejection->finding()->file()->value(),
            self::CODE => $rejection->finding()->code(),
            self::MESSAGE => $rejection->finding()->message(),
        ];
    }

    /**
     * A killed mutant as a ledger keeps it, with its mutator's index among
     * the ledger's mutator names and its killers' among its tests.
     *
     * @param  array<string, int>                 $mutators each mutator's index in the ledger, by its name
     * @param  array<string, int>                 $tests    each killing test's index in the ledger, by its id
     * @return array{string, int, int, list<int>}
     */
    public static function killed(Mutant|ProvedKill $killed, array $mutators, array $tests): array
    {
        return [
            $killed->id()->value(),
            $killed->location()->start()->number(),
            $mutators[$killed->mutator()],
            array_map(static fn(string $test): int => $tests[$test], self::idsOf($killed->killers())),
        ];
    }

    /** @throws NotInShape */
    public static function readFull(Node $record): Mutant
    {
        $mutant = Mutant::of(
            self::idIn($record),
            $record->field('native')->text(),
            Location::of(
                Path::of($record->field(self::FILE)->text()),
                self::lineIn($record->field(self::LINE)),
                self::endIn($record),
            ),
            Mutation::of(
                $record->field(self::MUTATOR)->text(),
                self::familyIn($record),
                $record->field(self::DIFF)->text(),
                self::hintIn($record),
            ),
            self::statusIn($record),
            self::secondsIn($record->field(self::SECONDS)),
        );
        $reason = self::reasonIn($record);
        $killers = $record->field(self::KILLED_BY);
        $limited = LimitRecord::onto($mutant, $record);
        $said = $reason instanceof Reason ? $limited->because($reason) : $limited;
        $killed = $killers->isPresent() ? $said->killedBy(self::testsIn($killers)) : $said;

        return self::rejectedIn($record, $killed);
    }

    /**
     * A killed record, read as the kill it proves in the unit it was proved in.
     *
     * @throws NotInShape
     */
    public static function readKilled(Node $record, Path $unit, KilledRecords $read): ProvedKill
    {
        $fields = $record->items();

        if (count($fields) !== self::KILLED) {
            throw NotInShape::at($record->at(), 'a killed mutant, as [id, line, mutator, killers]');
        }

        [$id, $line, $mutator, $killers] = $fields;

        return ProvedKill::at(
            self::idOf($id),
            $read->location($unit, self::lineIn($line)),
            $read->mutator($mutator),
            $read->killers($killers),
        );
    }

    /** Whether a record is a full one, rather than the killed one a ledger keeps of a killed mutant. */
    public static function isFull(Node $record): bool
    {
        return $record->field(self::DIFF)->isPresent();
    }

    /**
     * The mutant, rejected as the record says a static analyser rejected it.
     * A rejection belongs only to a mutant killed by static analysis, and is
     * its whole reason, so a record that gives one any other status, or a
     * reason or what a time budget ran out before beside it, contradicts
     * itself.
     *
     * @throws NotInShape
     */
    private static function rejectedIn(Node $record, Mutant $mutant): Mutant
    {
        $rejection = $record->field(self::REJECTION);

        return match (true) {
            ! $rejection->isPresent() => $mutant,
            $mutant->status() !== MutantStatus::KilledByStaticAnalysis => throw NotInShape::at(
                $rejection->at(),
                'a rejection only on a mutant killed by static analysis',
            ),
            $record->field(self::REASON)->isPresent() || $record->field(self::OUT_OF_TIME)->isPresent()
                => throw NotInShape::at($rejection->at(), 'a rejection with no reason beside it'),
            default => $mutant->rejected(Rejection::by(
                $rejection->field(self::ANALYSER)->text(),
                Finding::error(
                    Path::of($rejection->field(self::FILE)->text()),
                    $rejection->field(self::CODE)->text(),
                    $rejection->field(self::MESSAGE)->text(),
                ),
            )),
        };
    }

    /** @throws NotInShape */
    private static function idIn(Node $record): MutantId
    {
        return self::idOf($record->field(self::ID));
    }

    /** @throws NotInShape */
    private static function testsIn(Node $tests): TestIds
    {
        return TestIds::of(...array_map(static fn(Node $test): TestId => TestId::of($test->text()), $tests->items()));
    }

    /** @return list<string> */
    private static function idsOf(TestIds $tests): array
    {
        return array_map(static fn(TestId $test): string => $test->value(), [...$tests]);
    }

    /** @throws NotInShape */
    private static function idOf(Node $id): MutantId
    {
        $parsed = MutantId::parse($id->text());

        return $parsed instanceof CannotJudge ? throw NotInShape::at($id->at(), 'a mutant id') : $parsed;
    }

    /** @throws NotInShape */
    private static function lineIn(Node $line): Line
    {
        $number = $line->integer();

        return $number > 0 ? Line::of($number) : throw NotInShape::at($line->at(), 'a line');
    }

    /** @throws NotInShape */
    private static function endIn(Node $record): Line|Unreported
    {
        return $record->field(self::END)->isPresent() ? self::lineIn($record->field(self::END)) : Unreported::line();
    }

    /** @throws NotInShape */
    private static function familyIn(Node $record): MutatorFamily
    {
        $family = $record->field('family');

        return MutatorFamily::tryFrom($family->text()) ?? throw NotInShape::at($family->at(), 'a mutator family');
    }

    /**
     * A registered mutator's own sentence, where the record holds one.
     *
     * @throws NotInShape
     */
    private static function hintIn(Node $record): string|NotGiven
    {
        $hint = $record->field(self::HINT);

        return $hint->isPresent() ? $hint->text() : NotGiven::value();
    }

    /** @throws NotInShape */
    private static function statusIn(Node $record): MutantStatus
    {
        $status = $record->field(self::STATUS);

        return MutantStatus::tryFrom($status->text()) ?? throw NotInShape::at($status->at(), 'a status');
    }

    /** @return array{outOfTime?: string} what a time budget ran out before, where one did */
    private static function outOfTime(Reason $reason): array
    {
        $before = $reason->outOfTime();

        return $before instanceof OutOfTime ? [self::OUT_OF_TIME => $before->value] : [];
    }

    /**
     * Why a record's mutant is unjudged: a time budget's own reason where it
     * records one, and otherwise the runner's words, where it has any.
     *
     * @throws NotInShape
     */
    private static function reasonIn(Node $record): Reason|Unreported
    {
        $reason = $record->field(self::REASON);
        $before = $record->field(self::OUT_OF_TIME);

        return match (true) {
            $before->isPresent() => (OutOfTime::tryFrom($before->text())
                ?? throw NotInShape::at($before->at(), 'what a time budget ran out before'))->reason(),
            $reason->isPresent() => Reason::that($reason->text()),
            default => Unreported::reason(),
        };
    }

    /** @throws NotInShape */
    private static function secondsIn(Node $seconds): Seconds|Unmeasured
    {
        return $seconds->isPresent() ? Seconds::of($seconds->number()) : Unmeasured::duration();
    }
}
