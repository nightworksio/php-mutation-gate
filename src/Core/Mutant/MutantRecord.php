<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_key_exists;
use function array_map;
use function count;

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
 * runner reported of it, with the reason it left one unjudged and the tests
 * that killed it. The killed record, which a ledger keeps of a killed mutant,
 * is `[id, line, mutator, killers]`, the mutator an index into the ledger's
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

    /** How many fields a killed record holds: its id, its line, its mutator and its killers. */
    private const int KILLED = 4;

    private const string KILLED_BY = 'killedBy';

    /** @return Full */
    public static function full(Mutant $mutant): array
    {
        $end = $mutant->location()->end();
        $duration = $mutant->duration();
        $limit = $mutant->limit();
        $judging = $mutant->judgingTime();
        $reason = $mutant->reason();

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
            ...$reason instanceof Reason ? [self::REASON => $reason->text()] : [],
            ...count($mutant->killers()) > 0 ? [self::KILLED_BY => self::idsOf($mutant->killers())] : [],
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
    public static function killed(Mutant $mutant, array $mutators, array $tests): array
    {
        return [
            $mutant->id()->value(),
            $mutant->location()->start()->number(),
            $mutators[$mutant->mutation()->mutator()],
            array_map(static fn(string $test): int => $tests[$test], self::idsOf($mutant->killers())),
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
        $reason = $record->field(self::REASON);
        $killers = $record->field(self::KILLED_BY);
        $limited = $limit instanceof Seconds ? $mutant->withLimit($limit) : $mutant;
        $limited = $judging instanceof Seconds ? $limited->withJudgingTime($judging) : $limited;
        $said = $reason->isPresent() ? $limited->because(Reason::that($reason->text())) : $limited;

        return $killers->isPresent() ? $said->killedBy(self::testsIn($killers)) : $said;
    }

    /**
     * A killed record, read as a mutant of the unit it was proved in, with no
     * family, diff or duration.
     *
     * @param list<string> $mutators the ledger's mutator names, each at its index
     * @param list<string> $tests    the ledger's test ids, each at its index
     *
     * @throws NotInShape
     */
    public static function readKilled(Node $record, Path $unit, array $mutators, array $tests): Mutant
    {
        $fields = $record->items();

        if (count($fields) !== self::KILLED) {
            throw NotInShape::at($record->at(), 'a killed mutant, as [id, line, mutator, killers]');
        }

        [$id, $line, $mutator, $killers] = $fields;

        return Mutant::of(
            self::idOf($id),
            '',
            Location::of($unit, self::lineIn($line), Unreported::line()),
            Mutation::of(self::mutatorOf($mutator, $mutators), MutatorFamily::Unrecorded, ''),
            MutantStatus::Killed,
            Unmeasured::duration(),
        )->killedBy(self::killersOf($killers, $tests));
    }

    /** Whether a record is a full one, rather than the killed one a ledger keeps of a killed mutant. */
    public static function isFull(Node $record): bool
    {
        return $record->field(self::DIFF)->isPresent();
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

    /** @throws NotInShape */
    private static function secondsIn(Node $seconds): Seconds|Unmeasured
    {
        return $seconds->isPresent() ? Seconds::of($seconds->number()) : Unmeasured::duration();
    }
}
