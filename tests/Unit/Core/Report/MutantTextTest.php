<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('heads a mutant with where it is, its mutator, its judgement and its id', function (): void {
    $survivor = Verdicts::survivor();

    expect(MutantText::heading($survivor))->toBe(sprintf(
        'src/Money.php:7  LessToLessOrEqual  survived, on a changed line  %s',
        $survivor->mutant()->id()->value(),
    ));
});

it('writes a mutant with its diff, judging tests, hint, and reproduce and explain commands', function (): void {
    $survivor = Verdicts::survivor();
    $id = $survivor->mutant()->id()->value();

    expect(MutantText::block($survivor))->toBe(implode("\n", [
        sprintf('src/Money.php:7  LessToLessOrEqual  survived, on a changed line  %s', $id),
        '    --- Original',
        '    +++ New',
        '    @@ @@',
        '    -        if ($amount < $limit) {',
        '    +        if ($amount <= $limit) {',
        '    Judged by: MoneyTest::fits, MoneyTest::refuses, PriceTest::adds, CartTest::totals',
        '    No test uses a value at the boundary of `$amount < $limit`. It is judged by `MoneyTest::fits`, `MoneyTest::refuses`, `PriceTest::adds` and 1 more.',
        sprintf('    Reproduce: vendor/bin/mutation-gate reproduce %s', $id),
        sprintf('    Explain: vendor/bin/mutation-gate explain %s', $id),
    ]));
});

it('puts a mutant in one line for a tool that lists results', function (): void {
    $survivor = Verdicts::survivor();

    expect(MutantText::message($survivor))->toBe(sprintf(
        'Mutant survived: LessToLessOrEqual. %s Reproduce: vendor/bin/mutation-gate reproduce %s',
        $survivor->hint()->text(),
        $survivor->mutant()->id()->value(),
    ));
});

it('says why a mutant stands as it does, where its record says, and leaves out a diff it has none of', function (): void {
    $unjudged = iterator_to_array(Verdicts::everyJudgement(), preserve_keys: false)[4];
    $bare = JudgedMutant::of(Verdicts::mutant('src/Money.php:3', 'Plus', MutatorFamily::None, ''), MutantJudgement::Uncovered);

    expect(MutantText::block($unjudged))->toContain("\n    Why: The run's budget ran out before it.\n    Nothing judged it")
        ->and(explode("\n", MutantText::block($bare)))->toHaveCount(4);
});
