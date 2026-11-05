<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Heat\FileHeat;
use NightWorksIO\MutationGate\Core\Heat\Heat;
use NightWorksIO\MutationGate\Core\Heat\Heatmap;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Heatmaps;

$scored = static fn(Heat $heat): array => [
    $heat->name(),
    $heat->score() instanceof Score ? $heat->score()->hundredths() : null,
    $heat->base() instanceof Score ? $heat->base()->hundredths() : null,
    $heat->notKilled(),
];

$files = static fn(array $files): array => array_map(
    static fn(FileHeat $file): string => $file->path()->value(),
    $files,
);

it('scores every file and each function that holds its mutants, lowest first, against the base', function () use ($scored): void {
    $heatmap = Heatmaps::fallen();
    [$cart, $money] = [...$heatmap];

    expect(count($heatmap))->toBe(2)
        ->and($scored($cart->heat()))->toBe(['src/Cart.php', 5_000, 5_000, 1])
        ->and($cart->functions())->toBe([])
        ->and($scored($money->heat()))->toBe(['src/Money.php', 5_000, 7_500, 2])
        ->and(array_map($scored, $money->functions()))->toBe([['Money::sub', 5_000, 10_000, 1], ['Money::add', 10_000, 10_000, 0]])
        ->and($money->heat()->drop())->toBe(2_500)
        ->and($cart->heat()->drop())->toBe(0);
});

it('lists the lowest-scoring files it is told to, leaving out one with nothing to mutate', function () use ($files): void {
    $ignored = Heatmaps::tree(80, [
        ['src/Money.php:6', MutantJudgement::Ignored, false],
        ['src/Cart.php:3', MutantJudgement::Survived, false],
    ]);
    $heatmap = Heatmap::of($ignored, Heatmaps::spans(...));

    expect($files($heatmap->listing(5, 0)->lowest()))->toBe(['src/Cart.php'])
        ->and($files(Heatmaps::fallen()->listing(1, 0)->lowest()))->toBe(['src/Cart.php'])
        ->and($files($heatmap->lowest()))->toBe([]);
});

it('lists the touched files that fell most, then those below their floor, and none where it lists none', function () use ($files): void {
    $untouched = Heatmaps::tree(0, [['src/Money.php:6', MutantJudgement::Survived, false]]);
    $killed = Heatmaps::tree(0, [['src/Money.php:6', MutantJudgement::Killed, false]]);

    expect($files(Heatmaps::fallen()->fallen()))->toBe(['src/Money.php', 'src/Cart.php'])
        ->and($files(Heatmaps::fallen()->listing(10, 0)->fallen()))->toBe([])
        ->and($files(Heatmap::of($untouched, Heatmaps::spans(...))->comparedWith(Heatmap::of($killed, Heatmaps::spans(...)))->listing(1, 1)->fallen()))->toBe([])
        ->and(Heatmap::none()->fallen())->toBe([]);
});

it('lists at most as many fallen files as it is told, the one that fell most first', function () use ($files): void {
    $now = Heatmaps::tree(0, [
        ['src/Money.php:6', MutantJudgement::Survived, true],
        ['src/Money.php:7', MutantJudgement::Killed, false],
        ['src/Cart.php:3', MutantJudgement::Survived, true],
        ['src/Cart.php:4', MutantJudgement::Killed, false],
        ['src/Cart.php:5', MutantJudgement::Killed, false],
    ]);
    $base = Heatmaps::tree(0, [
        ['src/Money.php:6', MutantJudgement::Killed, false],
        ['src/Money.php:7', MutantJudgement::Killed, false],
        ['src/Cart.php:3', MutantJudgement::Killed, false],
        ['src/Cart.php:4', MutantJudgement::Killed, false],
        ['src/Cart.php:5', MutantJudgement::Killed, false],
    ]);
    $heatmap = Heatmap::of($now, Heatmaps::spans(...))->comparedWith(Heatmap::of($base, Heatmaps::spans(...)));

    expect($files($heatmap->listing(0, 2)->fallen()))->toBe(['src/Money.php', 'src/Cart.php'])
        ->and($files($heatmap->listing(0, 1)->fallen()))->toBe(['src/Money.php']);
});

it('says a file is below its tree\'s floor only where it scores under it', function (): void {
    [$cart, $money] = [...Heatmaps::fallen()];
    $exact = Heatmap::of(Heatmaps::tree(50, [
        ['src/Cart.php:3', MutantJudgement::Killed, false],
        ['src/Cart.php:4', MutantJudgement::Survived, false],
    ]), Heatmaps::spans(...));

    expect($cart->isBelowFloor())->toBeTrue()
        ->and($money->isTouched())->toBeTrue()
        ->and([...$exact][0]->isBelowFloor())->toBeFalse()
        ->and([...$exact][0]->isTouched())->toBeFalse();
});
