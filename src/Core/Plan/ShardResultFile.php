<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_filter;
use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Mutant\EvidenceRecord;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\KeysRecord;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\ProofRecord;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
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
 * miss lines of it, with why, where there are any, `covered` each held unit
 * whose holding tests cover it, with those of them that run it as `judging`,
 * where there are any, `warnings` what the shard warns of, where it warns of
 * anything, `unjudged` the units its budget ran out before or its doom left,
 * where there are any, `staticChecks` what static analysis's checks of its
 * survivors came to, where they came to anything (see SurvivorChecksRecord),
 * and `doomed` the survivor it stopped on once its run could not pass, where
 * it did (see DoomedRecord). `measured.steps` holds the steps the shard's
 * time went to, where it timed any (see StepsRecord). Each mutant's record
 * holds its kill's evidence beside it, where the runner gave any (see
 * EvidenceRecord). A result that cannot be read is refused, and the verdict
 * reads that shard as having left no result.
 *
 * @internal the shape of the shard result file
 */
final readonly class ShardResultFile
{
    /** The field that says why, of a held unit left unmutated and of a survivor left unchecked. */
    public const string WHY = 'why';

    /** The field that names a unit, of a held unit left unmutated or covered, and of a doom. */
    public const string UNIT = 'unit';
    private const int FORMAT = 1;

    private const string CANNOT_JUDGE = 'cannotJudge';

    private const string MEASURED = 'measured';

    /** Where a shard's measurement names the gate that made it, as its version spells it. */
    private const string GATE = LedgerFile::GATE;

    private const string FLAKY = 'flaky';

    private const string MISSED = 'missed';

    private const string COVERED = 'covered';

    private const string WARNINGS = 'warnings';

    private const string UNJUDGED = 'unjudged';

    public static function encode(ShardResult $result): string
    {
        $outcome = $result->outcome();
        $checks = SurvivorChecksRecord::of($result->checks());
        $doomed = DoomedRecord::of($result->doomed());

        return JsonText::encode([
            'format' => self::FORMAT,
            'plan' => $result->plan()->value(),
            'shard' => $result->shard()->number(),
            'units' => KeysRecord::of($result->units()),
            self::MEASURED => [
                'seconds' => $result->measured()->spent()->seconds(),
                'runner' => $result->measured()->runner(),
                'at' => $result->measured()->at()->value(),
                ...$result->measured()->gate() === '' ? [] : [self::GATE => $result->measured()->gate()],
                ...count($result->measured()->steps()) > 0
                    ? [StepsRecord::SECTION => StepsRecord::of($result->measured()->steps())]
                    : [],
            ],
            ...$outcome instanceof CannotJudge ? [self::CANNOT_JUDGE => $outcome->why()] : [
                'mutants' => array_map(
                    static fn(Mutant $mutant): array => [
                        ...MutantRecord::full($mutant),
                        ...EvidenceRecord::of($outcome->evidence()->of($mutant->id())),
                    ],
                    [...$outcome->mutants()],
                ),
                'skipped' => $outcome->skipped(),
            ],
            ...count($result->flaky()) > 0 ? [self::FLAKY => array_map(
                static fn(MutantId $id): string => $id->value(),
                [...$result->flaky()],
            )] : [],
            ...count($result->held()->misses()) > 0 ? [self::MISSED => array_map(
                static fn(NotCovered $miss): array => [
                    self::UNIT => UnitRecord::one($miss->unit()),
                    self::WHY => $miss->why(),
                ],
                [...$result->held()->misses()],
            )] : [],
            ...count($result->held()->covered()) > 0 ? [self::COVERED => array_map(
                static fn(Covered $covered): array => [
                    self::UNIT => UnitRecord::one($covered->unit()),
                    ProofRecord::JUDGING => array_map(
                        static fn(TestId $test): string => $test->value(),
                        [...$covered->tests()],
                    ),
                ],
                [...$result->held()->covered()],
            )] : [],
            ...count($result->warnings()) > 0 ? [self::WARNINGS => array_map(
                static fn(Warning $warning): string => $warning->text(),
                [...$result->warnings()],
            )] : [],
            ...count($result->unjudged()) > 0 ? [self::UNJUDGED => UnitRecord::all($result->unjudged())] : [],
            ...array_filter(
                [SurvivorChecksRecord::SECTION => $checks, DoomedRecord::SECTION => $doomed],
                static fn(array $section): bool => $section !== [],
            ),
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
            ->withHeld(self::heldIn($file->field(self::MISSED), $file->field(self::COVERED)))
            ->withWarnings(self::warningsIn($file->field(self::WARNINGS)))
            ->withUnjudged(self::unjudgedIn($file->field(self::UNJUDGED)))
            ->withChecks(SurvivorChecksRecord::read($file->field(SurvivorChecksRecord::SECTION)))
            ->withDoomed(DoomedRecord::read($file->field(DoomedRecord::SECTION)));
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
    private static function heldIn(Node $missed, Node $covered): HeldChecks
    {
        $held = HeldChecks::none();

        foreach ($missed->isPresent() ? $missed->items() : [] as $miss) {
            $held = $held->with(NotCovered::because(
                UnitRecord::read($miss->field(self::UNIT)),
                $miss->field(self::WHY)->text(),
            ));
        }

        foreach ($covered->isPresent() ? $covered->items() : [] as $one) {
            $tests = TestIds::none();

            foreach ($one->field(ProofRecord::JUDGING)->items() as $test) {
                $tests = $tests->with(TestId::of($test->text()));
            }

            $held = $held->with(Covered::by(UnitRecord::read($one->field(self::UNIT)), $tests));
        }

        return $held;
    }

    /** @throws NotInShape */
    private static function outcomeIn(Node $file): MutationResult|CannotJudge
    {
        if ($file->field(self::CANNOT_JUDGE)->isPresent()) {
            return CannotJudge::because($file->field(self::CANNOT_JUDGE)->text());
        }

        $mutants = [];
        $evidence = Evidences::none();

        foreach ($file->field('mutants')->items() as $record) {
            $mutant = MutantRecord::readFull($record);
            $mutants[] = $mutant;
            $evidence = $evidence->with($mutant->id(), EvidenceRecord::read($record));
        }

        return MutationResult::of(Mutants::of(...$mutants), $file->field('skipped')->integer())
            ->withEvidence($evidence);
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
        )->withSteps(StepsRecord::read($measured->field(StepsRecord::SECTION)))
            ->measuredBy($measured->field(self::GATE)->isPresent() ? $measured->field(self::GATE)->text() : '');
    }
}
