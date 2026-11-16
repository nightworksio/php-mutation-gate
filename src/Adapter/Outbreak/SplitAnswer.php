<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * What `outbreak split` answered of a whole map: how many slices it wrote,
 * `{"written": <count>}`, or `{"declined": <why>}`.
 *
 * @internal
 */
final readonly class SplitAnswer
{
    private const string FAILED = 'Outbreak did not split the map: %s';

    private const string DECLINED = 'Outbreak declined to split the map: %s';

    private const string WROTE = 'Outbreak wrote %d coverage maps from %s.';

    /** The slices of a whole map written, or why they were not: the binary declined, or did not answer. */
    public static function of(Node|NotWritten $said, Path $whole): Written|NotWritten
    {
        if ($said instanceof NotWritten) {
            return NotWritten::because(sprintf(self::FAILED, $said->why()));
        }

        $written = $said->field('written');

        try {
            return $written->isPresent()
                ? Written::noting($whole->value(), sprintf(self::WROTE, $written->integer(), $whole->value()))
                : NotWritten::because(sprintf(self::DECLINED, $said->field('declined')->text()));
        } catch (NotInShape) {
            return NotWritten::because(sprintf(self::FAILED, sprintf(BinaryRun::ANSWERED, $said->json())));
        }
    }
}
