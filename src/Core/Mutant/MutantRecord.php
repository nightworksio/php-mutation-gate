<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * A mutant as the gate's files write it. The full record holds everything a
 * runner reported of it, with the reason it left one unjudged. The killed
 * record, which a ledger keeps of a killed mutant, is `[id, line, mutator]`,
 * the mutator an index into the ledger's list of mutator names: what ignores
 * need of it, in as few bytes as a ledger of many thousands of them can take.
 *
 * @internal the shape of the plan, shard result and ledger files
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

    private const string REASON = 'reason';

    /** How many fields a killed record holds: its id, its line and its mutator. */
    private const int KILLED = 3;

    /** @return array<string, int|float|string> */
    public static function full(Mutant $mutant): array
    {
        $end = $mutant->location()->end();
        $duration = $mutant->duration();
        $limit = $mutant->limit();
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
            ...$reason instanceof Reason ? [self::REASON => $reason->text()] : [],
        ];
    }

    /**
     * A killed mutant as a ledger keeps it, with its mutator's index among
     * the ledger's mutator names.
     *
     * @return array{string, int, int}
     */
    public static function killed(Mutant $mutant, int $mutator): array
    {
        return [$mutant->id()->value(), $mutant->location()->start()->number(), $mutator];
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
        $reason = $record->field(self::REASON);
        $limited = $limit instanceof Seconds ? $mutant->withLimit($limit) : $mutant;

        return $reason->isPresent() ? $limited->because(Reason::that($reason->text())) : $limited;
    }

    /**
     * A killed record, read as a mutant of the unit it was proved in, with no
     * family, diff or duration.
     *
     * @param list<string> $mutators the ledger's mutator names, each at its index
     *
     * @throws NotInShape
     */
    public static function readKilled(Node $record, Path $unit, array $mutators): Mutant
    {
        $fields = $record->items();

        if (count($fields) !== self::KILLED) {
            throw NotInShape::at($record->at(), 'a killed mutant, as [id, line, mutator]');
        }

        [$id, $line, $mutator] = $fields;

        return Mutant::of(
            self::idOf($id),
            '',
            Location::of($unit, self::lineIn($line), Unreported::line()),
            Mutation::of(self::mutatorOf($mutator, $mutators), MutatorFamily::None, ''),
            MutantStatus::Killed,
            Unmeasured::duration(),
        );
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
