<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Growth;

$ids = static fn(TestIds $tests): array => array_map(static fn(TestId $test): string => $test->value(), iterator_to_array($tests, preserve_keys: true));

$map = static fn(): CoverageMap => CoverageMap::empty()
    ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::adds'))
    ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('MoneyTest::subtracts'))
    ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
    ->covered(Path::of('src/Money.php'), Line::of(20), TestId::of('LedgerTest::books'))
    ->covered(Path::of('123'), Line::of(1), TestId::of('NumberTest::counts'))
    ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.25))
    ->timed(TestId::of('IdleTest::waits'), Seconds::of(1.5));

it('knows nothing to begin with', function () use ($ids): void {
    $map = CoverageMap::empty();

    expect($ids($map->tests()))->toBe([])
        ->and($map->files())->toEqual(Paths::none());
});

it('knows every test that covered a line or was timed, each once', function () use ($map, $ids): void {
    expect($ids($map()->tests()))->toBe(['MoneyTest::adds', 'MoneyTest::subtracts', 'LedgerTest::books', 'NumberTest::counts', 'IdleTest::waits']);
});

it('lists every covered file in the order it was first covered', function () use ($map): void {
    expect($map()->files())->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('123')));
});

it('lists the covered lines of a file, and none for a file nothing covered', function () use ($map): void {
    expect($map()->linesCovered(Path::of('src/Money.php')))->toEqual(Lines::of(Line::of(3), Line::of(12), Line::of(20)))
        ->and($map()->linesCovered(Path::of('src/Other.php')))->toEqual(Lines::none());
});

it('answers the tests that covered one line', function () use ($map, $ids): void {
    expect($ids($map()->testsCovering(Path::of('src/Money.php'), Line::of(12))))->toBe(['MoneyTest::adds', 'MoneyTest::subtracts'])
        ->and($ids($map()->testsCovering(Path::of('src/Money.php'), Line::of(13))))->toBe([])
        ->and($ids($map()->testsCovering(Path::of('src/Other.php'), Line::of(12))))->toBe([]);
});

it('answers every test that covered any line of a file, each once', function () use ($map, $ids): void {
    expect($ids($map()->testsCoveringFile(Path::of('src/Money.php'))))->toBe(['MoneyTest::adds', 'MoneyTest::subtracts', 'LedgerTest::books'])
        ->and($ids($map()->testsCoveringFile(Path::of('src/Other.php'))))->toBe([]);
});

it('answers how long a test took, and says so where it was not timed', function () use ($map): void {
    expect($map()->durationOf(TestId::of('MoneyTest::adds')))->toEqual(Seconds::of(0.25))
        ->and($map()->durationOf(TestId::of('LedgerTest::books')))->toEqual(Unmeasured::duration());
});

it('leaves the map it came from as it was', function (): void {
    $map = CoverageMap::empty();
    $map->covered(Path::of('src/Money.php'), Line::of(1), TestId::of('MoneyTest::adds'));
    $map->timed(TestId::of('MoneyTest::adds'), Seconds::of(1.0));

    expect($map->files())->toHaveCount(0)
        ->and($map->tests())->toHaveCount(0)
        ->and($map->durationOf(TestId::of('MoneyTest::adds')))->toEqual(Unmeasured::duration());
});

it('builds a map at once as it would one entry after another', function () use ($map): void {
    $built = CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 12, 'MoneyTest::adds', 'MoneyTest::subtracts'),
        CoveredLine::of(Path::of('src/Money.php'), 3, 'MoneyTest::adds'),
        CoveredLine::of(Path::of('src/Money.php'), 20),
        CoveredLine::of(Path::of('src/Money.php'), 20, 'LedgerTest::books'),
        CoveredLine::of(Path::of('123'), 1, 'NumberTest::counts'),
        CoveredLine::of(Path::of('src/Money.php'), 12, 'MoneyTest::adds'),
    )->timedEach(
        TimedTest::of('MoneyTest::adds', 0.5),
        TimedTest::of('IdleTest::waits', 1.5),
        TimedTest::of('MoneyTest::adds', 0.25),
    );

    expect($built)->toEqual($map())
        ->and($built->testsCovering(Path::of('src/Money.php'), Line::of(12)))->toEqual($map()->testsCovering(Path::of('src/Money.php'), Line::of(12)))
        ->and($built->durationOf(TestId::of('MoneyTest::adds')))->toEqual(Seconds::of(0.25));
});

it('knows a test whose id is only digits by that id', function (): void {
    $map = CoverageMap::of(CoveredLine::of(Path::of('src/A.php'), 1, '7'))->timedEach(TimedTest::of('8', 1.0));

    expect($map->testsCovering(Path::of('src/A.php'), Line::of(1)))->toEqual(TestIds::of(TestId::of('7')))
        ->and($map->tests())->toEqual(TestIds::of(TestId::of('7'), TestId::of('8')))
        ->and($map->durationOf(TestId::of('8')))->toEqual(Seconds::of(1.0));
});

it('builds and reads a map in time linear in its entries', function (): void {
    $read = static function (int $size): Closure {
        $covered = [];

        foreach (range(1, $size) as $file) {
            foreach (range(1, 40) as $line) {
                $covered[] = CoveredLine::of(Path::of(sprintf('src/F%d.php', $file)), $line, sprintf('T%d::t', ($file * 40 + $line) % 3000));
            }
        }

        $timed = array_map(static fn(int $test): TimedTest => TimedTest::of(sprintf('T%d::t', $test), 0.5), range(0, $size - 1));

        return static function () use ($covered, $timed): int {
            $map = CoverageMap::of(...$covered)->timedEach(...$timed);
            $read = count($map->tests());

            foreach ($map->files() as $file) {
                $read += count($map->linesCovered($file)) + count($map->testsCoveringFile($file));
            }

            return $read;
        };
    };

    expect($read(10)())->toBe(410 + 10 * 80)
        ->and(Growth::of(1250, $read))->toBeLessThan(Growth::LINEAR);
});
