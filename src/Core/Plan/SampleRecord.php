<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\Unsampled;
use NightWorksIO\MutationGate\Core\Unit\UnitRecord;

/**
 * What a sampled run's plan drew, as the plan file holds it under `sample`
 * (ADR-0020, decision 28): the seed, the confidence and the margin in
 * points, the units of the sampled strata it runs, and those it leaves out,
 * each written as a unit is, with the mutants it is estimated to hold. A plan that samples nothing
 * holds no such field, and one whose field is not so shaped is refused, since
 * the verdict must estimate from the sample the shards ran.
 *
 * @internal the shape of the `sample` field of the plan file
 *
 * @phpstan-import-type Written from UnitRecord as UnitWritten
 *
 * @phpstan-type Written array{
 *     seed: string,
 *     confidence: int,
 *     margin: float,
 *     sampled: list<UnitWritten>,
 *     unsampled: list<array{path: string, group?: string, filter?: string, mutants: int}>,
 * }
 */
final readonly class SampleRecord
{
    public const string FIELD = 'sample';

    /** The field of an unsampled unit's record holding the mutants it is estimated to hold. */
    private const string MUTANTS = 'mutants';

    /** @return array{sample?: Written} the field, where the plan samples */
    public static function field(Sample $sample): array
    {
        return $sample->isDrawn() ? [self::FIELD => self::of($sample)] : [];
    }

    /** @return Written */
    public static function of(Sample $sample): array
    {
        return [
            'seed' => $sample->seed(),
            'confidence' => $sample->confidence()->value,
            'margin' => $sample->margin(),
            'sampled' => UnitRecord::all($sample->sampled()),
            'unsampled' => array_map(
                static fn(Candidate $unsampled): array => [
                    ...UnitRecord::one($unsampled->unit()),
                    self::MUTANTS => $unsampled->mutants(),
                ],
                [...$sample->unsampled()],
            ),
        ];
    }

    /**
     * What a plan's `sample` field holds; no sample where it is absent.
     *
     * @throws NotInShape
     */
    public static function read(Node $field): Sample
    {
        if (! $field->isPresent()) {
            return Sample::none();
        }

        $confidence = Confidence::tryFrom($field->field('confidence')->integer());
        $unsampled = Unsampled::none();

        foreach ($field->field('unsampled')->items() as $entry) {
            $unsampled = $unsampled->with(
                Candidate::of(UnitRecord::read($entry), $entry->field(self::MUTANTS)->integer(), NotGiven::value()),
            );
        }

        return Sample::drawn(
            $field->field('seed')->text(),
            $confidence instanceof Confidence
                ? $confidence
                : throw NotInShape::at($field->field('confidence')->at(), 'a confidence of 90, 95 or 99'),
            $field->field('margin')->number(),
        )->holding(UnitRecord::readAll($field->field('sampled')), $unsampled);
    }
}
