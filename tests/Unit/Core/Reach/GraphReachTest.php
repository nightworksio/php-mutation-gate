<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\GraphUse;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphReach;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Tree\Trees;

$nothing = static fn(): Reach => Reach::nothing(Packages::of(Trees::of()));

$said = static fn(Reach $reach): array => array_map(
    static fn(Reason $reason): string => $reason->text(),
    iterator_to_array($reach->reasons(), preserve_keys: false),
);

$answer = static fn(Paths $wrong): GraphAnswer => GraphAnswer::of(
    ['src/Money.php' => Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/LedgerTest.php')), 'src/Clock.php' => NotGiven::value()],
    ['src/Money.php' => Paths::of(Path::of('src/Rate.php')), 'src/Lone.php' => Paths::none()],
    [],
    $wrong,
    [],
);

it('narrows a change the map cannot place to the files the graph joins to it and those the map gives it', function () use ($nothing, $said, $answer): void {
    $narrowed = new GraphReach(GraphUse::Narrow, $answer(Paths::none()))->narrowed($nothing(), Path::of('src/Money.php'), Paths::of(Path::of('src/Mapped.php')));
    $reaches = static fn(string $file): bool => $narrowed instanceof Reach && $narrowed->reachesAt(Path::of($file));

    expect($reaches('src/Rate.php'))->toBeTrue()
        ->and($reaches('src/Mapped.php'))->toBeTrue()
        ->and($reaches('src/Other.php'))->toBeFalse()
        ->and($narrowed instanceof Reach ? $said($narrowed) : [])->toBe(['`src/Money.php` reaches the 2 files the tests that reach it reach, by the static graph (`outbreak.graph`: narrow).']);
});

it('leaves a change to the reach rules where the graph does not narrow or gives no answer', function () use ($nothing, $answer): void {
    $money = Path::of('src/Money.php');

    expect(new GraphReach(GraphUse::Widen, $answer(Paths::none()))->narrowed($nothing(), $money, Paths::none()))->toBeInstanceOf(NotGiven::class)
        ->and(new GraphReach(GraphUse::Narrow)->narrowed($nothing(), $money, Paths::none()))->toBeInstanceOf(NotGiven::class)
        ->and(new GraphReach(GraphUse::Narrow, $answer(Paths::none()))->narrowed($nothing(), Path::of('src/Clock.php'), Paths::none()))->toBeInstanceOf(NotGiven::class);
});

it('widens a changed source file to the files the map shows the test files the graph joins to it running', function () use ($nothing, $said, $answer): void {
    $judges = Judges::none()
        ->judging(Path::of('src/Ledger.php'), Paths::of(Path::of('tests/LedgerTest.php')))
        ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php')));
    $widened = new GraphReach(GraphUse::Widen, $answer(Paths::none()))->widened($nothing(), Path::of('src/Money.php'), $judges);

    expect($widened->reachesAt(Path::of('src/Ledger.php')))->toBeTrue()
        ->and($widened->reachesAt(Path::of('src/Money.php')))->toBeTrue()
        ->and($widened->reachesAt(Path::of('src/Rate.php')))->toBeFalse()
        ->and($said($widened))->toBe([<<<'SAID'
            `src/Money.php` changed, and the static graph joins 2 test files to it,
            so the 2 files they run are reached too (`outbreak.graph`: widen).
            SAID]);
});

it('widens a changed source file to the files the graph joins to it where there is no map', function () use ($nothing, $answer): void {
    $widened = new GraphReach(GraphUse::Widen, $answer(Paths::none()))->widened($nothing(), Path::of('src/Money.php'), NoMap::toRead());

    expect($widened->reachesAt(Path::of('src/Rate.php')))->toBeTrue();
});

it('leaves the reach as it is where the graph does not widen, gives no answer or joins no file', function () use ($nothing, $answer): void {
    $reach = $nothing();
    $judges = Judges::none()->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/OtherTest.php')));

    expect(new GraphReach(GraphUse::Narrow, $answer(Paths::none()))->widened($reach, Path::of('src/Money.php'), NoMap::toRead()))->toBe($reach)
        ->and(new GraphReach(GraphUse::Widen)->widened($reach, Path::of('src/Money.php'), NoMap::toRead()))->toBe($reach)
        ->and(new GraphReach(GraphUse::Widen, $answer(Paths::none()))->widened($reach, Path::of('src/Clock.php'), NoMap::toRead()))->toBe($reach)
        ->and(new GraphReach(GraphUse::Widen, $answer(Paths::none()))->widened($reach, Path::of('src/Money.php'), $judges))->toBe($reach);
});

it('notes which test files the map shows the graph wrong of, and nothing where it is right of all', function () use ($nothing, $said, $answer): void {
    $reach = $nothing();
    $noted = new GraphReach(GraphUse::Widen, $answer(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/ClockTest.php'))))->noted($reach);

    expect($said($noted))->toBe([<<<'SAID'
        The coverage map shows 2 test files running files the static graph does not join to them,
        so the graph answers nothing from them: `tests/MoneyTest.php`, `tests/ClockTest.php`.
        SAID])
        ->and(new GraphReach(GraphUse::Widen, $answer(Paths::none()))->noted($reach))->toBe($reach)
        ->and(new GraphReach()->noted($reach))->toBe($reach);
});
