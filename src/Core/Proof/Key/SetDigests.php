<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\LineSets;
use NightWorksIO\MutationGate\Core\Coverage\TestSet;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Test\TestId;

use function sprintf;

/**
 * The digest of each set of tests a coverage map names, as a key reads it, worked out once for every key.
 *
 * @internal
 */
final readonly class SetDigests
{
    /** @param array<string, string> $digests each set's digest, by the name the map gives it */
    private function __construct(private array $digests)
    {
    }

    /** Each set of tests this map names on a covered line of any file. */
    public static function of(CoverageMap $map): self
    {
        $sets = [];

        foreach ($map->files() as $file) {
            $lines = $map->lineSets($file);

            foreach ($lines as $set) {
                $sets[$set->name()] = [$set, $lines];
            }
        }

        $digests = [];

        foreach ($sets as $name => [$set, $lines]) {
            $digests[$name] = self::digested($set, $lines);
        }

        return new self($digests);
    }

    /** The digest of a set this map names: its count of tests, then each test's id, framed. */
    public function digestOf(TestSet $set): string
    {
        return $this->digests[$set->name()];
    }

    private static function digested(TestSet $set, LineSets $lines): string
    {
        $ids = array_map(static fn(TestId $test): string => $test->value(), [...$lines->testsOf($set)]);

        return Digest::sha256Of(ContentKeys::framed(sprintf('%d', count($ids)), ...$ids))->value();
    }
}
