<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\LineTests;
use NightWorksIO\MutationGate\Core\Coverage\PlacedLine;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Growth;

/**
 * Every line as its file, number and the ids of its tests.
 *
 * @return list<array{string, int, list<string>}>
 */
function lineTestsRead(LineTests $lines): array
{
    $read = [];

    foreach ($lines->lines() as $line) {
        $read[] = [$line->file()->value(), $line->line(), [...$line]];
    }

    return $read;
}

$money = Path::of('src/Money.php');
$tests = TestIds::of(TestId::of('A::a'), TestId::of('B::b'), TestId::of('C::c'));

it('holds lines placed as a map\'s file lists them, a line placed twice holding the tests of both, each once', function () use ($money, $tests): void {
    $lines = LineTests::placed(
        $tests,
        PlacedLine::of($money, 3, 0, 2),
        PlacedLine::of($money, 3, 2, 1),
        PlacedLine::of($money, 4, 1, 1),
    );

    expect(lineTestsRead($lines))->toBe([
        ['src/Money.php', 3, ['A::a', 'C::c', 'B::b']],
        ['src/Money.php', 4, ['B::b']],
    ])->and($lines->tests())->toEqual($tests);
});

it('holds a line placed with no test as missed, and never one some test also covers', function () use ($money, $tests): void {
    $lines = LineTests::placed(
        $tests,
        PlacedLine::of($money, 5),
        PlacedLine::of($money, 6),
        PlacedLine::of($money, 6, 0),
    );

    expect($lines->missedIn($money))->toEqual(Lines::of(Line::of(5)))
        ->and($lines->coveredIn($money))->toEqual(Lines::of(Line::of(6)))
        ->and(lineTestsRead($lines))->toBe([['src/Money.php', 6, ['A::a']], ['src/Money.php', 5, []]]);
});

it('places each test where it is first read, as a map\'s file would list it', function () use ($money): void {
    $read = LineTests::of(
        CoveredLine::of($money, 3, 'B::b', 'A::a'),
        CoveredLine::of($money, 4, 'A::a', 'C::c'),
        CoveredLine::of($money, 3, 'C::c', 'B::b'),
    );

    expect($read)->toEqual(LineTests::placed(
        TestIds::of(TestId::of('B::b'), TestId::of('A::a'), TestId::of('C::c')),
        PlacedLine::of($money, 3, 0, 1, 2),
        PlacedLine::of($money, 4, 1, 2),
    ));
});

it('knows a test once, after those it knew, and covers a line with it once', function () use ($money, $tests): void {
    $lines = LineTests::placed($tests, PlacedLine::of($money, 3, 1))
        ->knowing(TestId::of('B::b'), TestId::of('D::d'), TestId::of('D::d'))
        ->covered($money, Line::of(3), TestId::of('B::b'))
        ->covered($money, Line::of(3), TestId::of('D::d'));

    expect(lineTestsRead($lines))->toBe([['src/Money.php', 3, ['B::b', 'D::d']]])
        ->and(count($lines->tests()))->toBe(4);
});

it('holds covered lines in time linear in how many tests they name, however many tests there are', function (): void {
    $holding = static function (int $size): Closure {
        $covered = array_map(
            static fn(int $line): CoveredLine => CoveredLine::of(Path::of('src/A.php'), $line, ...array_map(
                static fn(int $test): string => sprintf('T%d::t', ($line + $test) % $size),
                range(1, 8),
            )),
            range(1, $size),
        );

        return static fn(): int => count(LineTests::of(...$covered)->tests());
    };

    expect($holding(10)())->toBe(10)
        ->and(Growth::of(500, $holding))->toBeLessThan(Growth::LINEAR);
});
