<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\AffectedTest;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;

/**
 * What `affected` found, for a script (ADR-0020, decision 1): `format`, the
 * `base` the change was read since, the ref asked for or else the commit the
 * map was measured at; the `map`'s `commit` and `dirty`; whether `all` tests
 * are listed; each listed test file with the map's `ids` of it and its
 * `reasons`; and each changed source file no test reaches, `unreached`. A
 * base or a map there is none of is `null`.
 *
 * @phpstan-type TestEntry array{file: string, ids: list<string>, reasons: list<string>}
 */
final readonly class AffectedJson
{
    private const int FORMAT = 1;

    public static function of(
        AffectedTests $tests,
        Revision|NotGiven $base,
        MeasuredAt|Unplaced|NotGiven $map,
    ): string {
        $listed = [];

        foreach ($tests->tests() as $test) {
            $listed[] = self::test($test);
        }

        return JsonText::printed([
            'format' => self::FORMAT,
            'base' => match (true) {
                $base instanceof Revision => $base->name(),
                $map instanceof MeasuredAt => $map->commit()->name(),
                default => null,
            },
            'map' => $map instanceof MeasuredAt ? $map->written() : null,
            'all' => $tests->isEvery(),
            'tests' => $listed,
            'unreached' => array_map(static fn(Path $source): string => $source->value(), [...$tests->unreached()]),
        ]);
    }

    /** @return TestEntry */
    private static function test(AffectedTest $test): array
    {
        $ids = [];
        $reasons = [];

        foreach ($test->tests() as $id) {
            $ids[] = $id->value();
        }

        foreach ($test->reasons() as $reason) {
            $reasons[] = $reason->text();
        }

        return ['file' => $test->file()->value(), 'ids' => $ids, 'reasons' => $reasons];
    }
}
