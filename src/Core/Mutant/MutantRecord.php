<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * A mutant as the gate's files write it. The full record holds everything a
 * runner reported of it; the brief one, which a ledger keeps of a killed
 * mutant, holds its id, its line, its mutator and its status, which is what
 * ignores need of it.
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

    /** @return array<string, int|float|string> */
    public static function full(Mutant $mutant): array
    {
        $end = $mutant->location()->end();
        $duration = $mutant->duration();
        $limit = $mutant->limit();

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
        ];
    }

    /** @return array<string, int|string> */
    public static function brief(Mutant $mutant): array
    {
        return [
            self::ID => $mutant->id()->value(),
            self::LINE => $mutant->location()->start()->number(),
            self::MUTATOR => $mutant->mutation()->mutator(),
            self::STATUS => $mutant->status()->value,
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

        return $limit instanceof Seconds ? $mutant->withLimit($limit) : $mutant;
    }

    /**
     * A brief record, read as a mutant of the unit it was proved in, with no
     * family, diff or duration.
     *
     * @throws NotInShape
     */
    public static function readBrief(Node $record, Path $unit): Mutant
    {
        return Mutant::of(
            self::idIn($record),
            '',
            Location::of($unit, self::lineIn($record->field(self::LINE)), Unreported::line()),
            Mutation::of($record->field(self::MUTATOR)->text(), MutatorFamily::None, ''),
            self::statusIn($record),
            Unmeasured::duration(),
        );
    }

    /** Whether a record is a full one, rather than the brief one a ledger keeps of a killed mutant. */
    public static function isFull(Node $record): bool
    {
        return $record->field(self::DIFF)->isPresent();
    }

    /** @throws NotInShape */
    private static function idIn(Node $record): MutantId
    {
        $id = MutantId::parse($record->field(self::ID)->text());

        return $id instanceof CannotJudge ? throw NotInShape::at($record->field(self::ID)->at(), 'a mutant id') : $id;
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
