<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\ExecutedFiles;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** Two tests: `adds` runs Money and Rate, `doubles` runs Money and Held. */
function executedMap(): CoverageMap
{
    return CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(4), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Rate.php'), Line::of(7), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('HeldTest::doubles'))
        ->covered(Path::of('src/Held.php'), Line::of(9), TestId::of('HeldTest::doubles'));
}

it('names every file the tests executed, each once', function (TestIds $tests, array $files): void {
    $executed = ExecutedFiles::in(executedMap())->by($tests);

    expect(array_map(static fn(Path $file): string => $file->value(), [...$executed]))->toEqualCanonicalizing($files);
})->with([
    'one test' => [fn(): TestIds => TestIds::of(TestId::of('MoneyTest::adds')), ['src/Money.php', 'src/Rate.php']],
    'two tests' => [
        fn(): TestIds => TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('HeldTest::doubles')),
        ['src/Money.php', 'src/Rate.php', 'src/Held.php'],
    ],
    'a test the map does not hold' => [fn(): TestIds => TestIds::of(TestId::of('GoneTest::went')), []],
    'no test' => [TestIds::none(), []],
]);
