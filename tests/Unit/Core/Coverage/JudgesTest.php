<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Growth;

it('knows of no file a test runs to begin with', function (): void {
    expect(Judges::none()->filesRunBy(Path::of('tests/MoneyTest.php')))->toEqual(Paths::none());
});

it('answers every covered file a test file judges, in the order they were added', function (): void {
    $judges = Judges::none()
        ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/LedgerTest.php')))
        ->judging(Path::of('src/Clock.php'), Paths::of(Path::of('tests/ClockTest.php')))
        ->judging(Path::of('src/Ledger.php'), Paths::of(Path::of('tests/LedgerTest.php')));

    expect($judges->filesRunBy(Path::of('tests/LedgerTest.php')))->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Ledger.php')))
        ->and($judges->filesRunBy(Path::of('tests/ClockTest.php')))->toEqual(Paths::of(Path::of('src/Clock.php')))
        ->and($judges->filesRunBy(Path::of('tests/OtherTest.php')))->toEqual(Paths::none());
});

it('replaces the test files of a covered file judged again, and leaves the judges it came from as they were', function (): void {
    $judges = Judges::none()->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php')));
    $again = $judges->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/LedgerTest.php')));

    expect($again->filesRunBy(Path::of('tests/MoneyTest.php')))->toEqual(Paths::none())
        ->and($again->filesRunBy(Path::of('tests/LedgerTest.php')))->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($judges->filesRunBy(Path::of('tests/MoneyTest.php')))->toEqual(Paths::of(Path::of('src/Money.php')));
});

it('answers the files each test file judges in time linear in their number', function (): void {
    $run = static function (int $size): Closure {
        $tests = array_map(static fn(int $at): Path => Path::of(sprintf('tests/T%dTest.php', $at)), range(0, $size - 1));
        $judges = Judges::none();

        foreach (range(0, $size - 1) as $at) {
            $judges = $judges->judging(Path::of(sprintf('src/F%d.php', $at)), Paths::of(...array_slice($tests, $at % ($size - 9), 10)));
        }

        return static function () use ($judges, $tests): int {
            $run = 0;

            foreach ($tests as $test) {
                $run += count($judges->filesRunBy($test));
            }

            return $run;
        };
    };

    expect($run(20)())->toBe(200)
        ->and(Growth::of(375, $run))->toBeLessThan(Growth::LINEAR);
});

it('keeps a covered file judged again where it was first judged', function (): void {
    $judges = Judges::none()
        ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php')))
        ->judging(Path::of('src/Ledger.php'), Paths::of(Path::of('tests/MoneyTest.php')))
        ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/LedgerTest.php')))
        ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php')))
        ->judging(Path::of('123'), Paths::of(Path::of('tests/MoneyTest.php')));

    expect($judges->filesRunBy(Path::of('tests/MoneyTest.php')))->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Ledger.php'), Path::of('123')))
        ->and($judges->filesRunBy(Path::of('tests/LedgerTest.php')))->toEqual(Paths::none());
});
