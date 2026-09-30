<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Judged;

it('is a mutant and what the gate made of it, on no changed line to begin with', function (): void {
    $judged = Judged::mutant('a', MutantJudgement::Flaky);

    expect($judged->mutant()->nativeId())->toBe('a')
        ->and($judged->judgement())->toBe(MutantJudgement::Flaky)
        ->and($judged->isOnChangedLine())->toBeFalse();
});

it('is on a changed line when the line it starts on changed in its file', function (string $file, int $line, bool $changed): void {
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Money.php'), Lines::of(Line::of(3), Line::of(4)));

    expect(Judged::mutant('a', MutantJudgement::Survived, $file, $line)->within($reach)->isOnChangedLine())->toBe($changed);
})->with([
    'a changed line' => ['src/Money.php', 4, true],
    'an unchanged line' => ['src/Money.php', 5, false],
    'the same line of another file' => ['src/Price.php', 4, false],
]);

it('keeps its mutant and judgement when marked', function (): void {
    $judged = Judged::mutant('a', MutantJudgement::Survived);
    $marked = $judged->within(Reach::nothing(Packages::of(Trees::none())));

    expect($marked->mutant())->toBe($judged->mutant())
        ->and($marked->judgement())->toBe(MutantJudgement::Survived);
});
