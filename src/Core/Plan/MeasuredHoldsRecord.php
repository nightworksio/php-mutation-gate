<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Hold\MeasuredHolds;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function sprintf;

/**
 * What the plan measured of the held units as the plan file holds it
 * (ADR-0161): by each held path, `tests`, the holding tests that run it,
 * `misses`, the sentence naming the lines they miss, or `failed`, why they
 * cannot run on their own. A plan without `held` measured none.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-type Written array<string, array{tests: list<string>}|array{misses: string}|array{failed: string}>
 */
final readonly class MeasuredHoldsRecord
{
    public const string FIELD = 'held';

    private const string TESTS = 'tests';

    private const string MISSES = 'misses';

    private const string FAILED = 'failed';

    /** @return Written */
    public static function of(MeasuredHolds $held): array
    {
        $written = [];

        foreach ($held->all() as $path => $measured) {
            $written[$path] = match (true) {
                $measured instanceof TestIds => [
                    self::TESTS => array_map(static fn(TestId $test): string => $test->value(), [...$measured]),
                ],
                $measured instanceof NotCovered => [self::MISSES => $measured->why()],
                default => [self::FAILED => $measured->why()],
            };
        }

        return $written;
    }

    /** @throws NotInShape */
    public static function read(Node $held): MeasuredHolds
    {
        $read = [];

        foreach ($held->entries() as $path => $measured) {
            $path = sprintf('%s', $path);
            $tests = $measured->field(self::TESTS);
            $misses = $measured->field(self::MISSES);
            $read[$path] = match (true) {
                $tests->isPresent() => TestIds::of(...array_map(
                    static fn(Node $test): TestId => TestId::of($test->text()),
                    $tests->items(),
                )),
                $misses->isPresent() => NotCovered::because(Unit::file(Path::of($path)), $misses->text()),
                default => CannotJudge::because($measured->field(self::FAILED)->text()),
            };
        }

        return MeasuredHolds::of($read);
    }
}
