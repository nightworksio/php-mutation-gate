<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\Cluster\ClusterKind;
use NightWorksIO\MutationGate\Core\Cluster\Membership;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Judged;

it('is a mutant and what the gate made of it, on no changed line to begin with', function (): void {
    $judged = Judged::mutant('a', MutantJudgement::Flaky);

    expect($judged->mutant()->nativeId())->toBe('a')
        ->and($judged->judgement())->toBe(MutantJudgement::Flaky)
        ->and($judged->isOnChangedLine())->toBeFalse()
        ->and($judged->tests())->toEqual(TestIds::none());
});

it('holds the tests that judged it, and keeps them when marked', function (): void {
    $tests = TestIds::of(TestId::of('MoneyTest::adds'));
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Money.php'), Lines::of(Line::of(1)));
    $judged = Judged::mutant('a', MutantJudgement::Killed)->judgedBy($tests)->within($reach);

    expect($judged->tests())->toBe($tests)
        ->and($judged->isOnChangedLine())->toBeTrue()
        ->and(Judged::mutant('a', MutantJudgement::Killed)->within($reach)->judgedBy($tests)->isOnChangedLine())->toBeTrue();
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

it('says what its tests miss from its diff and judging tests until it is given the hint its file gives', function (): void {
    $tests = TestIds::of(TestId::of('MoneyTest::adds'));
    $judged = Judged::mutant('a', MutantJudgement::Survived)->judgedBy($tests);
    $read = Hint::that('No test uses a value at the boundary of `$amount < $limit`.');

    expect($judged->hint())->toEqual(Hint::for($judged->mutant(), MutantJudgement::Survived, $tests, Missing::at(Path::of('src/Money.php'))))
        ->and($judged->hinted($read)->hint())->toBe($read)
        ->and($judged->hinted($read)->judgedBy(TestIds::none())->hint())->toBe($read);
});

it('gives the one command that explains it without running anything', function (): void {
    $judged = Judged::mutant('a', MutantJudgement::Survived);

    expect($judged->explain())->toBe(sprintf('vendor/bin/mutation-gate explain %s', $judged->mutant()->id()->value()));
});

it('turns a survivor proven equivalent into an equivalent one, and nothing else', function (MutantJudgement $judgement, MutantJudgement $proven): void {
    $judged = Judged::mutant('a', $judgement)->judgedBy(TestIds::of(TestId::of('MoneyTest::adds')));

    expect($judged->provenEquivalent()->judgement())->toBe($proven)
        ->and($judged->provenEquivalent()->tests())->toEqual($judged->tests());
})->with([
    'a survivor' => [MutantJudgement::Survived, MutantJudgement::Equivalent],
    'a killed mutant' => [MutantJudgement::Killed, MutantJudgement::Killed],
    'an uncovered one' => [MutantJudgement::Uncovered, MutantJudgement::Uncovered],
    'a flaky one' => [MutantJudgement::Flaky, MutantJudgement::Flaky],
    'an ignored one' => [MutantJudgement::Ignored, MutantJudgement::Ignored],
]);

it('gives the one command that reproduces it', function (): void {
    $judged = Judged::mutant('a', MutantJudgement::Survived);

    expect($judged->reproduce())->toBe(sprintf('vendor/bin/mutation-gate reproduce %s', $judged->mutant()->id()->value()));
});

it('is in no cluster until it is put in one, and keeps the rest when it is', function (): void {
    $mutant = Judged::mutant('1', MutantJudgement::Survived);
    $membership = Membership::of(ClusterId::of(MutantIds::of($mutant->mutant()->id())), ClusterKind::Gap);
    $clustered = $mutant->inCluster($membership);

    expect($mutant->cluster())->toEqual(Unclustered::mutant())
        ->and($clustered->cluster())->toBe($membership)
        ->and($clustered->mutant())->toBe($mutant->mutant())
        ->and($clustered->judgement())->toBe(MutantJudgement::Survived);
});
