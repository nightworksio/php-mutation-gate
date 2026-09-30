<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\KeysRecord;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\UnitRecord;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * A shard's result as `.mutation-gate/results/<id>.json` holds it,
 * `"format": 1`, with `flaky` listing the ids of the mutants that gave two
 * answers where there are any, `missed` each held unit whose holding tests
 * miss lines of it, with why, where there are any, `warnings` what the
 * shard warns of, where it warns of anything, and `unjudged` the units its
 * budget ran out before, where there are any. A result that cannot be read is
 * refused, and the verdict reads that shard as having left no result.
 *
 * @internal the shape of the shard result file
 */
final readonly class ShardResultFile
{
    private const int FORMAT = 1;

    private const string CANNOT_JUDGE = 'cannotJudge';

    private const string MEASURED = 'measured';

    private const string FLAKY = 'flaky';

    private const string MISSED = 'missed';

    private const string MISSED_UNIT = 'unit';

    private const string MISSED_WHY = 'why';

    private const string WARNINGS = 'warnings';

    private const string UNJUDGED = 'unjudged';

    public static function encode(ShardResult $result): string
    {
        $outcome = $result->outcome();

        return JsonText::encode([
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
            ...count($result->flaky()) > 0 ? [self::FLAKY => array_map(
                static fn(MutantId $id): string => $id->value(),
                [...$result->flaky()],
            )] : [],
            ...count($result->misses()) > 0 ? [self::MISSED => array_map(
                static fn(NotCovered $miss): array => [
                    self::MISSED_UNIT => UnitRecord::one($miss->unit()),
                    self::MISSED_WHY => $miss->why(),
                ],
                [...$result->misses()],
            )] : [],
            ...count($result->warnings()) > 0 ? [self::WARNINGS => array_map(
                static fn(Warning $warning): string => $warning->text(),
                [...$result->warnings()],
            )] : [],
            ...count($result->unjudged()) > 0 ? [self::UNJUDGED => UnitRecord::all($result->unjudged())] : [],
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
        )
            ->withFlaky(self::flakyIn($file->field(self::FLAKY)))
            ->withMisses(self::missesIn($file->field(self::MISSED)))
            ->withWarnings(self::warningsIn($file->field(self::WARNINGS)))
            ->withUnjudged(self::unjudgedIn($file->field(self::UNJUDGED)));
    }

    /** @throws NotInShape */
    private static function unjudgedIn(Node $unjudged): Units
    {
        return $unjudged->isPresent() ? UnitRecord::readAll($unjudged) : Units::none();
    }

    /** @throws NotInShape */
    private static function warningsIn(Node $warnings): Warnings
    {
        $read = Warnings::none();

        foreach ($warnings->isPresent() ? $warnings->items() : [] as $warning) {
            $read = $read->with(Warning::that($warning->text()));
        }

        return $read;
    }

    /** @throws NotInShape */
    private static function missesIn(Node $missed): HeldMisses
    {
        $misses = [];

        foreach ($missed->isPresent() ? $missed->items() : [] as $miss) {
            $misses[] = NotCovered::because(
                UnitRecord::read($miss->field(self::MISSED_UNIT)),
                $miss->field(self::MISSED_WHY)->text(),
            );
        }

        return HeldMisses::of(...$misses);
    }

    /** @throws NotInShape */
    private static function outcomeIn(Node $file): MutationResult|CannotJudge
    {
        if ($file->field(self::CANNOT_JUDGE)->isPresent()) {
            return CannotJudge::because($file->field(self::CANNOT_JUDGE)->text());
        }

        $mutants = [];

        foreach ($file->field('mutants')->items() as $record) {
            $mutants[] = MutantRecord::readFull($record);
        }

        return MutationResult::of(Mutants::of(...$mutants), $file->field('skipped')->integer());
    }

    /** @throws NotInShape */
    private static function flakyIn(Node $flaky): MutantIds
    {
        $ids = [];

        foreach ($flaky->isPresent() ? $flaky->items() : [] as $item) {
            $id = MutantId::parse($item->text());
            $ids[] = $id instanceof MutantId ? $id : throw NotInShape::at($item->at(), 'a mutant id');
        }

        return MutantIds::of(...$ids);
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
