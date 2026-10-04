<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

/**
 * How much of the kill matrix a plan's run, or the run of a proof, records,
 * as the plan and ledger files hold it: `matrix`, `full` where it records
 * every killer of each mutant. A record without it records first killers.
 *
 * @internal the shape of the plan and ledger files
 *
 * @phpstan-type Written array{matrix?: string}
 */
final readonly class MatrixRecord
{
    public const string FIELD = 'matrix';

    /** @return Written */
    public static function of(MatrixKind $matrix): array
    {
        return $matrix === MatrixKind::Full ? [self::FIELD => $matrix->value] : [];
    }

    /**
     * How much of the kill matrix the record this field is in holds.
     *
     * @throws NotInShape
     */
    public static function read(Node $matrix): MatrixKind
    {
        return match (true) {
            ! $matrix->isPresent() => MatrixKind::FirstKiller,
            $matrix->text() === MatrixKind::Full->value => MatrixKind::Full,
            default => throw NotInShape::at($matrix->at(), sprintf('"%s"', MatrixKind::Full->value)),
        };
    }
}
