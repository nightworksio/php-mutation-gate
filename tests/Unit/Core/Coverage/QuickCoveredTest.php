<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\QuickCovered;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

$ids = static fn(TestIds $tests): array => array_map(static fn(TestId $test): string => $test->value(), iterator_to_array($tests, preserve_keys: false));

it('covers every line of a file by each of its tests, beside what the map already holds', function () use ($ids): void {
    $file = Path::of('src/Money.php');
    $map = CoverageMap::of()->covered($file, Line::of(2), TestId::of('LedgerTest::books'));
    $covered = QuickCovered::over(
        $map,
        QuickCovered::of($file, 3, TestIds::of(TestId::of('MoneyTest::adds'))),
        QuickCovered::of(Path::of('src/One.php'), 1, TestIds::of(TestId::of('OneTest::reads'))),
    );

    expect($ids($covered->testsCovering($file, Line::of(1), Line::of(1))))->toBe(['MoneyTest::adds'])
        ->and($ids($covered->testsCovering($file, Line::of(2), Line::of(2))))->toBe(['LedgerTest::books', 'MoneyTest::adds'])
        ->and($ids($covered->testsCovering($file, Line::of(3), Line::of(3))))->toBe(['MoneyTest::adds'])
        ->and($ids($covered->testsCovering($file, Line::of(4), Line::of(4))))->toBe([])
        ->and($ids($covered->testsCovering(Path::of('src/One.php'), Line::of(1), Line::of(1))))->toBe(['OneTest::reads']);
});

it('leaves the map as it is for a file no test covers or one with no lines', function (): void {
    $map = CoverageMap::of()->covered(Path::of('src/Money.php'), Line::of(2), TestId::of('LedgerTest::books'));

    expect(QuickCovered::over(
        $map,
        QuickCovered::of(Path::of('src/Clock.php'), 5, TestIds::none()),
        QuickCovered::of(Path::of('src/Empty.php'), 0, TestIds::of(TestId::of('EmptyTest::reads'))),
    ))->toBe($map);
});
