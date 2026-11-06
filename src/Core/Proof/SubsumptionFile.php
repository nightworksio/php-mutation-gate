<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;

use function sprintf;

/**
 * Which mutant subsumes which, as a plan hands a shard the part of it of the
 * shard's files, for its run to hold the subsumed back (ADR-0079).
 *
 * @internal
 */
final readonly class SubsumptionFile
{
    private const int FORMAT = 1;

    private const string NAME = 'subsumption.json';

    /** Where it stands in the directory a job hands it over in, beside the coverage map. */
    public static function in(Path $directory): Path
    {
        return Path::of(sprintf('%s/%s', $directory->value(), self::NAME));
    }

    public static function encode(Subsumption $subsumption): string
    {
        return JsonText::compact([
            'format' => self::FORMAT,
            SubsumptionRecord::SECTION => SubsumptionRecord::of($subsumption),
        ]);
    }

    public static function decode(string $json): Subsumption|CannotJudge
    {
        try {
            $file = Node::decode($json);

            return $file->field('format')->integer() === self::FORMAT
                ? SubsumptionRecord::read($file->field(SubsumptionRecord::SECTION))
                : throw NotInShape::at($file->field('format')->at(), sprintf('format %d', self::FORMAT));
        } catch (NotInShape $refused) {
            return CannotJudge::because(sprintf('What subsumes what cannot be read: %s', $refused->getMessage()));
        }
    }
}
