<?php

declare(strict_types=1);

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
