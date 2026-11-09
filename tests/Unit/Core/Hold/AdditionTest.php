<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Addition;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

it('adds a test that holds the path on a line of a file inside it, and no other test or file', function (
    string $test,
    string $file,
    bool $adds,
): void {
    $addition = Addition::of(Path::of('src/Git'), TestIds::of(TestId::of('GitTest::runs')), 'holds:src/Git');

    expect($addition->adds(TestId::of($test), Path::of($file)))->toBe($adds);
})->with([
    'its test, on a file inside the path' => ['GitTest::runs', 'src/Git/Command.php', true],
    'its test, on the path itself' => ['GitTest::runs', 'src/Git', true],
    'its test, on a file outside it' => ['GitTest::runs', 'src/Money.php', false],
    'its test, on a file whose name only begins as the path does' => ['GitTest::runs', 'src/GitHub.php', false],
    'another test, on a file inside the path' => ['MoneyTest::adds', 'src/Git/Command.php', false],
]);

it('holds its path, and names its declaration as written', function (): void {
    $addition = Addition::of(Path::of('src/Git'), TestIds::none(), "#[Holds('src/Git')] on GitTest");

    expect($addition->path())->toEqual(Path::of('src/Git'))
        ->and($addition->written())->toBe("#[Holds('src/Git')] on GitTest");
});

it('is held by its tests alone, and runs where one of them runs a line of a file inside its path', function (): void {
    $addition = Addition::of(Path::of('src/Git'), TestIds::of(TestId::of('GitTest::runs')), 'holds:src/Git');
    $outline = static fn(string $file): MapOutline => MapOutline::of(CoverageMap::of(
        CoveredLine::of(Path::of($file), 3, 'GitTest::runs'),
        CoveredLine::of(Path::of('src/Git/Command.php'), 4, 'MoneyTest::adds'),
    ));

    expect($addition->isHeldBy(TestId::of('GitTest::runs')))->toBeTrue()
        ->and($addition->isHeldBy(TestId::of('MoneyTest::adds')))->toBeFalse()
        ->and($addition->runsIn($outline('src/Git/Pull.php')))->toBeTrue()
        ->and($addition->runsIn($outline('src/GitHub.php')))->toBeFalse();
});
