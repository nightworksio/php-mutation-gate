<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_key_exists;
use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * A mutant as the gate's files write it. The full record holds everything a
 * runner reported of it, with the reason it left one unjudged, what a time
 * budget ran out before where one did, and the tests that killed it, or the
 * static analyser's rejection that did. The killed record, which a ledger
 * keeps of a mutant tests killed, is
 * `[id, line, mutator, killers]`, the mutator an index into the ledger's
 * list of mutator names and the killers indices into its list of tests: what
 * ignores and the tests report need of it, in as few bytes as a ledger of
 * many thousands of them can take.
 *
 * @internal the shape of the plan, shard result and ledger files
 *
 * @phpstan-type Full array{
 *     id: string,
 *     native: string,
 *     file: string,
 *     line: int,
 *     end?: int,
 *     mutator: string,
 *     family: string,
 *     diff: string,
 *     status: string,
 *     seconds?: float,
 *     limit?: float,
 *     testSeconds?: float,
 *     reason?: string,
 *     outOfTime?: string,
 *     killedBy?: list<string>,
 * }
 */
final readonly class MutantRecord
{
    private const string ID = 'id';

    private const string LINE = 'line';

    private const string STATUS = 'status';

    private const string END = 'end';

    private const string SECONDS = 'seconds';

    private const string MUTATOR = 'mutator';

    private const string DIFF = 'diff';

    private const string LIMIT = 'limit';

    private const string TEST_SECONDS = 'testSeconds';

    private const string REASON = 'reason';

    /** What a time budget ran out before, where one left the mutant unjudged. */
    private const string OUT_OF_TIME = 'outOfTime';

    /** How many fields a killed record holds: its id, its line, its mutator and its killers. */
    private const int KILLED = 4;

    private const string KILLED_BY = 'killedBy';

    private const string REJECTION = 'rejection';

    private const string ANALYSER = 'analyser';

    private const string CODE = 'code';

    private const string MESSAGE = 'message';

    /** @return Full */
    public static function full(Mutant $mutant): array
    {
        $end = $mutant->location()->end();
        $duration = $mutant->duration();
        $limit = $mutant->limit();
        $judging = $mutant->judgingTime();
        $reason = $mutant->reason();
        $rejection = $mutant->rejection();

        return [
            self::ID => $mutant->id()->value(),
            'native' => $mutant->nativeId(),
            'file' => $mutant->location()->file()->value(),
            self::LINE => $mutant->location()->start()->number(),
            ...$end instanceof Line ? [self::END => $end->number()] : [],
            self::MUTATOR => $mutant->mutation()->mutator(),
            'family' => $mutant->mutation()->family()->value,
            self::DIFF => $mutant->mutation()->diff(),
            self::STATUS => $mutant->status()->value,
            ...$duration instanceof Seconds ? [self::SECONDS => $duration->seconds()] : [],
            ...$limit instanceof Seconds ? [self::LIMIT => $limit->seconds()] : [],
            ...$judging instanceof Seconds ? [self::TEST_SECONDS => $judging->seconds()] : [],
            ...$reason instanceof Reason ? [self::REASON => $reason->text(), ...self::outOfTime($reason)] : [],
            ...count($mutant->killers()) > 0 ? [self::KILLED_BY => self::idsOf($mutant->killers())] : [],
            ...$rejection instanceof Rejection ? [self::REJECTION => [
                self::ANALYSER => $rejection->analyser(),
                self::CODE => $rejection->finding()->code(),
                self::MESSAGE => $rejection->finding()->message(),
            ]] : [],
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
                Path::of($record->field('file')->text()),
                self::lineIn($record->field(self::LINE)),
                self::endIn($record),
            ),
            Mutation::of(
                $record->field(self::MUTATOR)->text(),
                self::familyIn($record),
                $record->field(self::DIFF)->text(),
            ),
            self::statusIn($record),
            self::secondsIn($record->field(self::SECONDS)),
        );
        $limit = self::secondsIn($record->field(self::LIMIT));
        $judging = self::secondsIn($record->field(self::TEST_SECONDS));
        $reason = self::reasonIn($record);
        $killers = $record->field(self::KILLED_BY);
        $limited = $limit instanceof Seconds ? $mutant->withLimit($limit) : $mutant;
        $limited = $judging instanceof Seconds ? $limited->withJudgingTime($judging) : $limited;
        $said = $reason instanceof Reason ? $limited->because($reason) : $limited;
        $killed = $killers->isPresent() ? $said->killedBy(self::testsIn($killers)) : $said;

        return self::rejectedIn($record, $killed);
    }

    /**
     * A killed record, read as the kill it proves in the unit it was proved in.
     *
     * @param list<string> $mutators the ledger's mutator names, each at its index
     * @param list<string> $tests    the ledger's test ids, each at its index
     *
     * @throws NotInShape
     */
    public static function readKilled(Node $record, Path $unit, array $mutators, array $tests): ProvedKill
    {
        $fields = $record->items();

        if (count($fields) !== self::KILLED) {
            throw NotInShape::at($record->at(), 'a killed mutant, as [id, line, mutator, killers]');
        }

        [$id, $line, $mutator, $killers] = $fields;

        return ProvedKill::of(
            self::idOf($id),
            $unit,
            self::lineIn($line),
            self::mutatorOf($mutator, $mutators),
            self::killersOf($killers, $tests),
        );
    }

    /** Whether a record is a full one, rather than the killed one a ledger keeps of a killed mutant. */
    public static function isFull(Node $record): bool
    {
        return $record->field(self::DIFF)->isPresent();
    }

    /**
     * The mutant, rejected as the record says a static analyser rejected it.
     *
     * @throws NotInShape
     */
    private static function rejectedIn(Node $record, Mutant $mutant): Mutant
    {
        $rejection = $record->field(self::REJECTION);

        return $rejection->isPresent() ? $mutant->rejected(Rejection::by(
            $rejection->field(self::ANALYSER)->text(),
            Finding::error($rejection->field(self::CODE)->text(), $rejection->field(self::MESSAGE)->text()),
        )) : $mutant;
    }

    /** @throws NotInShape */
    private static function idIn(Node $record): MutantId
    {
        return self::idOf($record->field(self::ID));
    }

    /**
     * The tests a killed record's indices name.
     *
     * @param list<string> $tests
     *
     * @throws NotInShape
     */
    private static function killersOf(Node $killers, array $tests): TestIds
    {
        $named = [];

        foreach ($killers->integers() as $index) {
            $named[] = array_key_exists($index, $tests)
                ? TestId::of($tests[$index])
                : throw NotInShape::at($killers->at(), sprintf('the index of a listed test, not %d', $index));
        }

        return TestIds::of(...$named);
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

    /**
     * @param list<string> $mutators
     *
     * @throws NotInShape
     */
    private static function mutatorOf(Node $mutator, array $mutators): string
    {
        $index = $mutator->integer();

        return array_key_exists($index, $mutators)
            ? $mutators[$index]
            : throw NotInShape::at($mutator->at(), 'a mutator');
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
