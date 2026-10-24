<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_map;

use ArrayObject;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\LineSets;
use NightWorksIO\MutationGate\Core\Coverage\TestSet;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Test\TestId;

use function sprintf;

/** The digest of each set of tests a coverage map names, as a key reads it, worked out once for every key. */
final readonly class SetDigests
{
    /** @param ArrayObject<string, string> $digests each set's digest, by the name the map gives it */
    private function __construct(private ArrayObject $digests)
    {
    }

    public static function none(): self
    {
        return new self(new ArrayObject());
    }

    /** The digest of a set: its count of tests, then each test's id, framed. */
    public function of(TestSet $set, LineSets $lines): string
    {
        return $this->digests[$set->name()] ??= $this->digested($lines, $set);
    }

    private function digested(LineSets $lines, TestSet $set): string
    {
        $ids = array_map(static fn(TestId $test): string => $test->value(), [...$lines->testsOf($set)]);

        return Digest::sha256Of(ContentKeys::framed(sprintf('%d', count($ids)), ...$ids))->value();
    }
}
