<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The run that judged a pass as its passed record holds it, each field where
 * it is known (ADR-0198): `inputs`, the digest of every input it read, `run`,
 * its address, and `whole`, true only where it carried no unit's result.
 *
 * @internal the shape of the ledger and plan files
 *
 * @phpstan-type Written array{inputs?: string, run?: string, whole?: true}
 */
final readonly class JudgingRunRecord
{
    /** The field of the digest of every input a run read, which a plan file holds too. */
    public const string INPUTS = 'inputs';

    private const string ADDRESS = 'run';

    private const string WHOLE = 'whole';

    /** @return Written */
    public static function of(JudgingRun $run): array
    {
        $inputs = $run->inputs();

        return [
            ...$inputs instanceof Digest ? [self::INPUTS => $inputs->value()] : [],
            ...$run->address() === '' ? [] : [self::ADDRESS => $run->address()],
            ...$run->isWhole() ? [self::WHOLE => true] : [],
        ];
    }

    /**
     * The run a passed record holds, unrecorded where it holds none of its fields.
     *
     * @throws NotInShape where a field it holds is not so shaped
     */
    public static function read(Node $record): JudgingRun
    {
        $inputs = $record->field(self::INPUTS);
        $address = $record->field(self::ADDRESS);

        return JudgingRun::of(
            match (true) {
                ! $inputs->isPresent() => NotGiven::value(),
                Digest::isSha256($inputs->text()) => Digest::of($inputs->text()),
                default => throw NotInShape::at($inputs->at(), 'a digest'),
            },
            $address->isPresent() ? $address->text() : '',
            whole: RunProfileRecord::isTrue($record->field(self::WHOLE)),
        );
    }
}
