<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethods;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** @return list<array{string, int, list<string>}> each covered line of a map, with its tests in order */
function linesOfMap(CoverageMap $map): array
{
    return array_map(
        static fn(CoveredLine $line): array => [$line->file()->value(), $line->line(), [...$line]],
        iterator_to_array($map->lines(), preserve_keys: false),
    );
}

it('replaces the entries of the tests measured again, keeps every other test\'s lines and time, and misses a line none of them runs', function (): void {
    $kept = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('HeldTest::doubles'))
        ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Gone.php'), Line::of(3), TestId::of('GoneTest::goes'))
        ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.5))
        ->timed(TestId::of('HeldTest::doubles'), Seconds::of(0.25))
        ->timed(TestId::of('GoneTest::goes'), Seconds::of(1.0))
        ->executing(Path::of('src/Money.php'), ExecutedMethod::of('add', 10, 13));
    $measured = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(13), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Price.php'), Line::of(5), TestId::of('MoneyTest::prices'))
        ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.75))
        ->executing(Path::of('src/Price.php'), ExecutedMethod::of('price', 4, 6));

    $merged = Remeasured::over(
        $kept,
        TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('GoneTest::goes')),
        $measured,
    );

    expect(linesOfMap($merged))->toBe([
        ['src/Money.php', 11, ['HeldTest::doubles']],
        ['src/Money.php', 13, ['MoneyTest::adds']],
        ['src/Price.php', 5, ['MoneyTest::prices']],
        ['src/Money.php', 12, []],
        ['src/Gone.php', 3, []],
    ])
        ->and($merged->durationOf(TestId::of('HeldTest::doubles')))->toEqual(Seconds::of(0.25))
        ->and($merged->durationOf(TestId::of('MoneyTest::adds')))->toEqual(Seconds::of(0.75))
        ->and($merged->durationOf(TestId::of('GoneTest::goes')))->toEqual(Unmeasured::duration())
        ->and($merged->tests()->has(TestId::of('GoneTest::goes')))->toBeFalse()
        ->and($merged->methods()->at(Path::of('src/Money.php'), ExecutedMethods::none()))->toEqual($kept->methods()->at(Path::of('src/Money.php'), ExecutedMethods::none()))
        ->and($merged->methods()->at(Path::of('src/Price.php'), ExecutedMethods::none()))->toEqual($measured->methods()->at(Path::of('src/Price.php'), ExecutedMethods::none()));
});

it('keeps the map as it was where no test is measured again and the new measure holds nothing', function (): void {
    $kept = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
        ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.5));

    expect(Remeasured::over($kept, TestIds::none(), CoverageMap::empty()))->toEqual($kept);
});

it('takes none of the lines the new measure missed, which ran some tests alone', function (): void {
    $kept = CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 11, 'MoneyTest::adds', 'HeldTest::doubles'),
        CoveredLine::of(Path::of('src/Money.php'), 12),
    );
    $measured = CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 11),
        CoveredLine::of(Path::of('src/Money.php'), 12, 'MoneyTest::adds'),
        CoveredLine::of(Path::of('src/Constants.php'), 10),
    );

    expect(linesOfMap(Remeasured::over($kept, TestIds::of(TestId::of('MoneyTest::adds')), $measured)))->toBe([
        ['src/Money.php', 11, ['HeldTest::doubles']],
        ['src/Money.php', 12, ['MoneyTest::adds']],
    ]);
});

it('keeps the lines a test measured again ran in a file unchanged since the kept map, as boot code a warm cache skips, and none in a changed file or of a test the new measure no longer holds', function (Paths|NotGiven $changed, array $lines): void {
    $kept = CoverageMap::empty()
        ->covered(Path::of('src/Kernel.php'), Line::of(20), TestId::of('CommandTest::adds'))
        ->covered(Path::of('src/Kernel.php'), Line::of(21), TestId::of('GoneTest::goes'))
        ->covered(Path::of('src/Validator.php'), Line::of(8), TestId::of('CommandTest::adds'))
        ->covered(Path::of('src/Validator.php'), Line::of(9), TestId::of('CommandTest::adds'));
    $measured = CoverageMap::empty()
        ->covered(Path::of('src/Validator.php'), Line::of(9), TestId::of('CommandTest::adds'));

    $merged = Remeasured::over(
        $kept,
        TestIds::of(TestId::of('CommandTest::adds'), TestId::of('GoneTest::goes')),
        $measured,
        $changed,
    );

    expect(linesOfMap($merged))->toBe($lines);
})->with([
    'the validator changed' => [
        fn(): Paths => Paths::of(Path::of('src/Validator.php')),
        [
            ['src/Kernel.php', 20, ['CommandTest::adds']],
            ['src/Validator.php', 9, ['CommandTest::adds']],
            ['src/Kernel.php', 21, []],
            ['src/Validator.php', 8, []],
        ],
    ],
    'nothing changed' => [
        fn(): Paths => Paths::none(),
        [
            ['src/Kernel.php', 20, ['CommandTest::adds']],
            ['src/Validator.php', 8, ['CommandTest::adds']],
            ['src/Validator.php', 9, ['CommandTest::adds']],
            ['src/Kernel.php', 21, []],
        ],
    ],
    'what changed unknown' => [
        fn(): NotGiven => NotGiven::value(),
        [
            ['src/Validator.php', 9, ['CommandTest::adds']],
            ['src/Kernel.php', 20, []],
            ['src/Kernel.php', 21, []],
            ['src/Validator.php', 8, []],
        ],
    ],
]);
