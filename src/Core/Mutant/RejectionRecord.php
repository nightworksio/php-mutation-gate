<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/**
 * A static analyser's rejection of a mutant, as a mutant's full record and
 * the JSON report write it: the analyser, and its finding's file, code and
 * message. A ledger keeps the analyser and the code alone; reading one, the
 * finding sits in the mutant's own file and says nothing more.
 *
 * @internal the shape of the plan, shard result, ledger and JSON report files
 *
 * @phpstan-type Written array{analyser: string, file: string, code: string, message: string}
 * @phpstan-type Kept array{analyser: string, code: string}
 */
final readonly class RejectionRecord
{
    public const string ANALYSER = 'analyser';

    /** The file the finding sits in. */
    public const string FILE = MutantRecord::FILE;

    public const string CODE = 'code';

    public const string MESSAGE = 'message';

    /** @return Written */
    public static function of(Rejection $rejection): array
    {
        return [
            self::ANALYSER => $rejection->analyser(),
            self::FILE => $rejection->finding()->file()->value(),
            self::CODE => $rejection->finding()->code(),
            self::MESSAGE => $rejection->finding()->message(),
        ];
    }

    /** @return Kept */
    public static function kept(Rejection $rejection): array
    {
        return [self::ANALYSER => $rejection->analyser(), self::CODE => $rejection->finding()->code()];
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
    public static function onto(Node $record, Mutant $mutant): Mutant
    {
        $rejection = $record->field(MutantRecord::REJECTION);

        return match (true) {
            ! $rejection->isPresent() => $mutant,
            $mutant->status() !== MutantStatus::KilledByStaticAnalysis => throw NotInShape::at(
                $rejection->at(),
                'a rejection only on a mutant killed by static analysis',
            ),
            $record->field(MutantRecord::REASON)->isPresent() || $record->field(MutantRecord::OUT_OF_TIME)->isPresent()
                => throw NotInShape::at($rejection->at(), 'a rejection with no reason beside it'),
            default => $mutant->rejected(Rejection::by(
                $rejection->field(self::ANALYSER)->text(),
                Finding::error(
                    self::fileIn($rejection, $mutant),
                    $rejection->field(self::CODE)->text(),
                    self::messageIn($rejection),
                ),
            )),
        };
    }

    /** @throws NotInShape */
    private static function fileIn(Node $rejection, Mutant $mutant): Path
    {
        $file = $rejection->field(self::FILE);

        return $file->isPresent() ? Path::of($file->text()) : $mutant->location()->file();
    }

    /** @throws NotInShape */
    private static function messageIn(Node $rejection): string
    {
        $message = $rejection->field(self::MESSAGE);

        return $message->isPresent() ? $message->text() : '';
    }
}
