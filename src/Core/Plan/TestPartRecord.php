<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\TestPart;

use function sprintf;

/**
 * The part of the test files whose tests judge a shard's units, as the plan
 * file writes it beside the shard's units (ADR-0117): `testPart`, counted
 * from 1, and `testParts`, how many parts there are, where the run cuts its
 * test files across its shards. A shard that names neither is judged by
 * every test file.
 *
 * @internal the shape of the plan file
 */
final readonly class TestPartRecord
{
    private const string PART = 'testPart';

    private const string PARTS = 'testParts';

    /** @return array<string, int> */
    public static function of(TestPart $part): array
    {
        return $part->isWhole() ? [] : [self::PART => $part->number(), self::PARTS => $part->parts()];
    }

    /** @throws NotInShape */
    public static function read(Node $shard): TestPart
    {
        $part = $shard->field(self::PART);

        if (! $part->isPresent()) {
            return TestPart::whole();
        }

        $parts = $shard->field(self::PARTS)->integer();
        $number = $part->integer();

        return $parts > 1 && $number >= 1 && $number <= $parts
            ? TestPart::of($number, $parts)
            : throw NotInShape::at($part->at(), sprintf('a part of %d parts', $parts));
    }
}
