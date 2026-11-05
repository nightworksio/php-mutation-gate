<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Config\HeatmapRows;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Heat\FileHeat;
use NightWorksIO\MutationGate\Core\Heat\Heat;
use NightWorksIO\MutationGate\Core\Heat\Heatmap;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Heatmaps;

$scored = static fn(Heat $heat): array => [
    $heat->name(),
    $heat->score() instanceof Score ? $heat->score()->hundredths() : null,
    $heat->base() instanceof Score ? $heat->base()->hundredths() : null,
    $heat->notKilled(),
];

/**
 * @param  Listed<FileHeat> $files
 * @return list<string>     each file's path
 */
function heatmapPaths(Listed $files): array
{
    return array_map(static fn(FileHeat $file): string => $file->path()->value(), [...$files]);
}

it('scores every file and each function that holds its mutants, lowest first, against the base', function () use ($scored): void {
    $heatmap = Heatmaps::fallen();
    [$cart, $money] = [...$heatmap];

    expect(count($heatmap))->toBe(2)
        ->and($scored($cart->heat()))->toBe(['src/Cart.php', 5_000, 5_000, 1])
        ->and([...$cart->functions()])->toBe([])
        ->and($scored($money->heat()))->toBe(['src/Money.php', 5_000, 7_500, 2])
        ->and(array_map($scored, [...$money->functions()]))->toBe([['Money::sub', 5_000, 10_000, 1], ['Money::add', 10_000, 10_000, 0]])
        ->and($money->heat()->drop())->toBe(2_500)
        ->and($cart->heat()->drop())->toBe(0);
});

it('lists the lowest-scoring files it is told to, leaving out one with nothing to mutate', function (): void {
    $ignored = Heatmaps::tree(80, [
        ['src/Money.php:6', MutantJudgement::Ignored, false],
        ['src/Cart.php:3', MutantJudgement::Survived, false],
    ]);
    $heatmap = Heatmap::of($ignored, Heatmaps::spans(...));

    expect(heatmapPaths($heatmap->listing(HeatmapRows::of(files: 5))->lowest()))->toBe(['src/Cart.php'])
        ->and(heatmapPaths(Heatmaps::fallen()->listing(HeatmapRows::of(files: 1))->lowest()))->toBe(['src/Cart.php'])
        ->and(heatmapPaths($heatmap->lowest()))->toBe([]);
});

it('lists the touched files that fell most, then those below their floor, and none where it lists none', function (): void {
    $untouched = Heatmaps::tree(0, [['src/Money.php:6', MutantJudgement::Survived, false]]);
    $killed = Heatmaps::tree(0, [['src/Money.php:6', MutantJudgement::Killed, false]]);

    expect(heatmapPaths(Heatmaps::fallen()->fallen(CommentShows::of())))->toBe(['src/Money.php', 'src/Cart.php'])
        ->and(heatmapPaths(Heatmaps::fallen()->fallen(CommentShows::of(caps: ['heatmap' => 0]))))->toBe([])
        ->and(heatmapPaths(Heatmap::of($untouched, Heatmaps::spans(...))->comparedWith(Heatmap::of($killed, Heatmaps::spans(...)))->listing(HeatmapRows::of(files: 1))->fallen(CommentShows::of(caps: ['heatmap' => 1]))))->toBe([])
        ->and([...Heatmap::none()->fallen(CommentShows::of())])->toBe([]);
});

it('lists at most as many fallen files as it is told, the one that fell most first', function (): void {
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

    expect(heatmapPaths($heatmap->listing(HeatmapRows::of(files: 0))->fallen(CommentShows::of(caps: ['heatmap' => 2]))))->toBe(['src/Money.php', 'src/Cart.php'])
        ->and(heatmapPaths($heatmap->listing(HeatmapRows::of(files: 0))->fallen(CommentShows::of(caps: ['heatmap' => 1]))))->toBe(['src/Money.php']);
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

it('scores the files of every tree, and lists none before it is told how many', function (): void {
    $cart = Heatmaps::tree(0, [['src/Cart.php:3', MutantJudgement::Survived, true]]);
    $money = Heatmaps::tree(0, [['src/Money.php:6', MutantJudgement::Killed, true], ['src/Money.php:7', MutantJudgement::Survived, true]]);
    $heatmap = Heatmap::of(TreeVerdicts::of(...[...$cart], ...[...$money]), Heatmaps::spans(...));
    $unlisted = Heatmap::of(TreeVerdicts::of(...[...$cart], ...[...$money]), Heatmaps::spans(...))
        ->comparedWith(Heatmap::of(Heatmaps::tree(0, [['src/Cart.php:3', MutantJudgement::Killed, false], ['src/Money.php:6', MutantJudgement::Killed, false]]), Heatmaps::spans(...)));

    expect(heatmapPaths(Listed::of(...$heatmap)))->toBe(['src/Cart.php', 'src/Money.php'])
        ->and(heatmapPaths($heatmap->lowest()))->toBe([])
        ->and(heatmapPaths($unlisted->fallen()))->toBe([])
        ->and(heatmapPaths($unlisted->listing(HeatmapRows::of(files: 0, comment: 2))->fallen()))->toBe(['src/Cart.php', 'src/Money.php']);
});

it('lists a touched file that fell by a hundredth of a percent, and none that held', function (): void {
    $mutants = static fn(int $killed, int $all): array => array_map(
        static fn(int $at): array => [sprintf('src/Cart.php:%d', $at), $at <= $killed ? MutantJudgement::Killed : MutantJudgement::Survived, $at === 1],
        range(1, $all),
    );
    $rows = HeatmapRows::of(files: 1, comment: 1);
    $fell = Heatmap::of(Heatmaps::tree(0, $mutants(33, 80)), Heatmaps::spans(...))
        ->comparedWith(Heatmap::of(Heatmaps::tree(0, $mutants(26, 63)), Heatmaps::spans(...)));
    $held = Heatmap::of(Heatmaps::tree(0, $mutants(26, 63)), Heatmaps::spans(...))
        ->comparedWith(Heatmap::of(Heatmaps::tree(0, $mutants(26, 63)), Heatmaps::spans(...)));

    expect([...$fell][0]->heat()->drop())->toBe(1)
        ->and(heatmapPaths($fell->listing($rows)->fallen()))->toBe(['src/Cart.php'])
        ->and(heatmapPaths($held->listing($rows)->fallen()))->toBe([]);
});
