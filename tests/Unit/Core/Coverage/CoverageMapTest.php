<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethods;
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
})->group('holds:src/Core/Coverage/LineTests.php');

it('knows every test that covered a line or was timed, each once', function () use ($map, $ids): void {
    expect($ids($map()->tests()))->toBe(['MoneyTest::adds', 'MoneyTest::subtracts', 'LedgerTest::books', 'NumberTest::counts', 'IdleTest::waits']);
})->group('holds:src/Core/Coverage/LineTests.php');

it('lists every covered file in the order it was first covered', function () use ($map): void {
    expect($map()->files())->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('123')));
})->group('holds:src/Core/Coverage/LineTests.php');

it('lists the covered lines of a file, and none for a file nothing covered', function () use ($map): void {
    expect($map()->linesCovered(Path::of('src/Money.php')))->toEqual(Lines::of(Line::of(3), Line::of(12), Line::of(20)))
        ->and($map()->linesCovered(Path::of('src/Other.php')))->toEqual(Lines::none());
})->group('holds:src/Core/Coverage/LineTests.php');

it('answers the tests that covered one line', function () use ($map, $ids): void {
    expect($ids($map()->testsCovering(Path::of('src/Money.php'), Line::of(12))))->toBe(['MoneyTest::adds', 'MoneyTest::subtracts'])
        ->and($ids($map()->testsCovering(Path::of('src/Money.php'), Line::of(13))))->toBe([])
        ->and($ids($map()->testsCovering(Path::of('src/Other.php'), Line::of(12))))->toBe([]);
})->group('holds:src/Core/Coverage/LineTests.php');

it('answers every test that covered any line of a file, each once', function () use ($map, $ids): void {
    expect($ids($map()->testsCoveringFile(Path::of('src/Money.php'))))->toBe(['MoneyTest::adds', 'MoneyTest::subtracts', 'LedgerTest::books'])
        ->and($ids($map()->testsCoveringFile(Path::of('src/Other.php'))))->toBe([]);
})->group('holds:src/Core/Coverage/LineTests.php');

it('answers every test that covered any line of a span, each once, in the order the lines name them', function () use ($map, $ids): void {
    $money = Path::of('src/Money.php');

    expect($ids($map()->testsCoveringSpan($money, Line::of(3), Line::of(12))))->toBe(['MoneyTest::adds', 'MoneyTest::subtracts'])
        ->and($ids($map()->testsCoveringSpan($money, Line::of(12), Line::of(20))))->toBe(['MoneyTest::adds', 'MoneyTest::subtracts', 'LedgerTest::books'])
        ->and($ids($map()->testsCoveringSpan($money, Line::of(13), Line::of(19))))->toBe([])
        ->and($ids($map()->testsCoveringSpan($money, Line::of(20), Line::of(20))))->toBe(['LedgerTest::books'])
        ->and($ids($map()->testsCoveringSpan(Path::of('src/Other.php'), Line::of(1), Line::of(99))))->toBe([]);
})->group('holds:src/Core/Coverage/LineTests.php');

it('answers the tests of a span in time linear in how many cover it', function (): void {
    $span = static function (int $size): Closure {
        $map = CoverageMap::of(...array_map(
            static fn(int $line): CoveredLine => CoveredLine::of(Path::of('src/A.php'), $line, ...array_map(static fn(int $test): string => sprintf('T%d::t', $test), range(1, $size))),
            range(1, 4),
        ));

        return static fn(): int => count($map->testsCoveringSpan(Path::of('src/A.php'), Line::of(1), Line::of(4)));
    };

    expect($span(10)())->toBe(10)
        ->and(Growth::of(2000, $span))->toBeLessThan(Growth::LINEAR);
})->group('holds:src/Core/Coverage/LineTests.php');

it('answers how long a test took, and says so where it was not timed', function () use ($map): void {
    expect($map()->durationOf(TestId::of('MoneyTest::adds')))->toEqual(Seconds::of(0.25))
        ->and($map()->durationOf(TestId::of('LedgerTest::books')))->toEqual(Unmeasured::duration());
})->group('holds:src/Core/Coverage/LineTests.php');

it('leaves the map it came from as it was', function (): void {
    $map = CoverageMap::empty();
    $map->covered(Path::of('src/Money.php'), Line::of(1), TestId::of('MoneyTest::adds'));
    $map->timed(TestId::of('MoneyTest::adds'), Seconds::of(1.0));

    expect($map->files())->toHaveCount(0)
        ->and($map->tests())->toHaveCount(0)
        ->and($map->durationOf(TestId::of('MoneyTest::adds')))->toEqual(Unmeasured::duration());
})->group('holds:src/Core/Coverage/LineTests.php');

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
})->group('holds:src/Core/Coverage/LineTests.php');

it('holds each file\'s executed methods, in the order given, and keeps them as it grows', function () use ($map): void {
    $add = ExecutedMethod::of('add', 10, 13);
    $book = ExecutedMethod::of('book', 20, 24);
    $held = $map()->executing(Path::of('src/Money.php'), $add)->executing(Path::of('src/Money.php'), $book)
        ->covered(Path::of('src/Money.php'), Line::of(21), TestId::of('LedgerTest::books'))
        ->timed(TestId::of('LedgerTest::books'), Seconds::of(0.5));

    expect($held->methods()->at(Path::of('src/Money.php'), ExecutedMethods::none()))->toEqual(ExecutedMethods::of($add, $book))
        ->and($held->methods()->at(Path::of('123'), ExecutedMethods::none()))->toEqual(ExecutedMethods::none())
        ->and($held->methods()->paths())->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($map()->methods()->paths())->toEqual(Paths::none())
        ->and($held->onlyFor(Paths::of(Path::of('src/Money.php')))->methods()->at(Path::of('src/Money.php'), ExecutedMethods::none()))->toEqual(ExecutedMethods::of($add, $book))
        ->and($held->onlyFor(Paths::of(Path::of('123')))->methods()->paths())->toEqual(Paths::none());
})->group('holds:src/Core/Coverage/LineTests.php');

it('names an executed method and the lines it spans', function (): void {
    $method = ExecutedMethod::of('add', 10, 13);

    expect([$method->name(), $method->first(), $method->last()])->toEqual(['add', Line::of(10), Line::of(13)])
        ->and(count(ExecutedMethods::of($method, $method)))->toBe(2)
        ->and([...ExecutedMethods::of(...['a' => $method])])->toBe([$method]);
})->group('holds:src/Core/Coverage/LineTests.php');

it('knows a test whose id is only digits by that id', function (): void {
    $map = CoverageMap::of(CoveredLine::of(Path::of('src/A.php'), 1, '7'))->timedEach(TimedTest::of('8', 1.0));

    expect($map->testsCovering(Path::of('src/A.php'), Line::of(1)))->toEqual(TestIds::of(TestId::of('7')))
        ->and($map->tests())->toEqual(TestIds::of(TestId::of('7'), TestId::of('8')))
        ->and($map->durationOf(TestId::of('8')))->toEqual(Seconds::of(1.0));
})->group('holds:src/Core/Coverage/LineTests.php');

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
        ->and(Growth::of(625, $read))->toBeLessThan(Growth::LINEAR);
});

it('times the whole suite, test after test, adding nothing for a test it did not time', function (): void {
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(4), TestId::of('MoneyTest::untimed'))
        ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.25))
        ->timed(TestId::of('IdleTest::waits'), Seconds::of(1.5));

    expect($map->suiteDuration())->toEqual(Seconds::of(1.75))
        ->and(CoverageMap::empty()->suiteDuration())->toEqual(Seconds::of(0.0));
})->group('holds:src/Core/Coverage/LineTests.php');

it('holds each executable line no test ran, never one some test covers, whatever the order it reads them in', function (): void {
    $money = Path::of('src/Money.php');
    $read = CoverageMap::of(
        CoveredLine::of($money, 4),
        CoveredLine::of($money, 5),
        CoveredLine::of($money, 5, 'MoneyTest::adds'),
        CoveredLine::of($money, 6, 'MoneyTest::adds'),
        CoveredLine::of($money, 6),
        CoveredLine::of(Path::of('src/Tax.php'), 9),
    );

    expect([...$read->lineSets($money)->missed()])->toEqual([Line::of(4)])
        ->and([...$read->lineSets(Path::of('src/Tax.php'))->missed()])->toEqual([Line::of(9)])
        ->and([...$read->lineSets(Path::of('src/Other.php'))->missed()])->toBe([])
        ->and([...$read->linesCovered($money)])->toEqual([Line::of(5), Line::of(6)])
        ->and($read->files())->toEqual(Paths::of($money))
        ->and([...$read->covered($money, Line::of(4), TestId::of('MoneyTest::adds'))->lineSets($money)->missed()])->toBe([])
        ->and(CoverageMap::of(CoveredLine::of($money, 4))->covered($money, Line::of(4), TestId::of('MoneyTest::adds')))
        ->toEqual(CoverageMap::empty()->covered($money, Line::of(4), TestId::of('MoneyTest::adds')))
        ->and([...$read->onlyFor(Paths::of($money))->lineSets(Path::of('src/Tax.php'))->missed()])->toBe([])
        ->and([...$read->onlyFor(Paths::of($money))->lineSets($money)->missed()])->toEqual([Line::of(4)])
        ->and($read->timed(TestId::of('MoneyTest::adds'), Seconds::of(1.0))->lineSets($money)->missed())->toEqual($read->lineSets($money)->missed())
        ->and($read->executing($money, ExecutedMethod::of('add', 4, 6))->lineSets($money)->missed())->toEqual($read->lineSets($money)->missed());
})->group('holds:src/Core/Coverage/LineTests.php');

it('lists every executable line: the covered ones with their tests, then those no test ran, with none', function (): void {
    $money = Path::of('src/Money.php');
    $lines = array_map(
        static fn(CoveredLine $line): array => [$line->file()->value(), $line->line(), [...$line]],
        iterator_to_array(CoverageMap::of(CoveredLine::of($money, 4), CoveredLine::of($money, 5, 'MoneyTest::adds'))->lines(), preserve_keys: false),
    );

    expect($lines)->toBe([['src/Money.php', 5, ['MoneyTest::adds']], ['src/Money.php', 4, []]]);
})->group('holds:src/Core/Coverage/LineTests.php');

it('names each covered line\'s set of tests alike wherever the set recurs, in ascending order of line, beside the lines no test ran', function (): void {
    $money = Path::of('src/Money.php');
    $tax = Path::of('src/Tax.php');
    $map = CoverageMap::of(
        CoveredLine::of($money, 9, 'b::t', 'a::t'),
        CoveredLine::of($money, 3, 'a::t', 'b::t'),
        CoveredLine::of($money, 5, '9'),
        CoveredLine::of($money, 5, '10'),
        CoveredLine::of($money, 4),
        CoveredLine::of($tax, 2, 'b::t', 'a::t', 'a::t'),
    );
    $sets = $map->lineSets($money);
    $covered = $sets->covered();

    expect(array_keys($covered))->toBe([3, 5, 9])
        ->and($covered[3])->toBe($covered[9])
        ->and($map->lineSets($tax)->covered()[2])->toBe($covered[3])
        ->and($covered[5])->not->toBe($covered[3])
        ->and($sets->idsOf($covered[3]))->toBe(['a::t', 'b::t'])
        ->and($sets->idsOf($covered[5]))->toBe(['10', '9'])
        ->and([...$sets->missed()])->toEqual([Line::of(4)])
        ->and($map->lineSets(Path::of('src/Gone.php'))->covered())->toBe([]);
});
