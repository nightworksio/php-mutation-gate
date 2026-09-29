<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\KeysRecord;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A shard's result as `.mutation-gate/results/<id>.json` holds it,
 * `"format": 1`. A result that cannot be read is refused, and the verdict
 * reads that shard as having left no result.
 *
 * @internal the shape of the shard result file
 */
final readonly class ShardResultFile
{
    private const int FORMAT = 1;

    private const string CANNOT_JUDGE = 'cannotJudge';

    private const string MEASURED = 'measured';

    public static function encode(ShardResult $result): string
    {
        $outcome = $result->outcome();

        return Json::encode([
            'format' => self::FORMAT,
            'plan' => $result->plan()->value(),
            'shard' => $result->shard()->number(),
            'units' => KeysRecord::of($result->units()),
            self::MEASURED => [
                'seconds' => $result->measured()->spent()->seconds(),
                'runner' => $result->measured()->runner(),
                'at' => $result->measured()->at()->value(),
            ],
            ...$outcome instanceof CannotJudge ? [self::CANNOT_JUDGE => $outcome->why()] : [
                'mutants' => array_map(
                    MutantRecord::full(...),
                    [...$outcome->mutants()],
                ),
                'skipped' => $outcome->skipped(),
            ],
        ]);
    }

    public static function decode(string $json): ShardResult|CannotJudge
    {
        try {
            return self::resultIn(Node::decode($json));
        } catch (NotInShape $refused) {
            return CannotJudge::because(sprintf('A shard result cannot be read: %s', $refused->getMessage()));
        }
    }

    /** @throws NotInShape */
    private static function resultIn(Node $file): ShardResult
    {
        if ($file->field('format')->integer() !== self::FORMAT) {
            throw NotInShape::at($file->field('format')->at(), sprintf('format %d', self::FORMAT));
        }

        $shard = $file->field('shard')->integer();

        return ShardResult::of(
            Digest::of($file->field('plan')->text()),
            $shard > 0 ? ShardId::of($shard) : throw NotInShape::at($file->field('shard')->at(), 'a shard number'),
            KeysRecord::read($file->field('units')),
            self::outcomeIn($file),
            self::measuredIn($file->field(self::MEASURED)),
        );
    }

    /** @throws NotInShape */
    private static function outcomeIn(Node $file): MutationResult|CannotJudge
    {
        if ($file->field(self::CANNOT_JUDGE)->isPresent()) {
            return CannotJudge::because($file->field(self::CANNOT_JUDGE)->text());
        }

        $mutants = Mutants::none();

        foreach ($file->field('mutants')->items() as $record) {
            $mutants = $mutants->with(MutantRecord::readFull($record));
        }

        return MutationResult::of($mutants, $file->field('skipped')->integer());
    }

    /** @throws NotInShape */
    private static function measuredIn(Node $measured): Measurement
    {
        $at = Instant::parse($measured->field('at')->text());

        return Measurement::of(
            Seconds::of($measured->field('seconds')->number()),
            $measured->field('runner')->text(),
            $at instanceof CannotJudge ? throw NotInShape::at($measured->field('at')->at(), 'an instant') : $at,
        );
    }
}
