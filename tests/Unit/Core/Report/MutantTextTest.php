<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Removing;
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

    expect(MutantText::block($survivor, TestNames::none()))->toBe(implode("\n", [
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

it('names each judging test as its runner named it, and by its id where it named it nothing', function (): void {
    $names = Verdicts::matrix(MatrixKind::FirstKiller)->names();

    expect(MutantText::block(Verdicts::survivor(), $names))->toContain(
        "\n    Judged by: tests/Unit/MoneyTest.php::it fits, tests/Unit/MoneyTest.php::it fits with data set \"over\", PriceTest::adds, CartTest::totals\n",
    );
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
    $unjudged = Judged::listed(Verdicts::everyJudgement())[4];
    $bare = JudgedMutant::of(Verdicts::mutant('src/Money.php:3', 'Plus', MutatorFamily::None, ''), MutantJudgement::Uncovered);

    expect(MutantText::block($unjudged, TestNames::none()))->toContain("\n    Why: The run's budget ran out before it.\n    Nothing judged it")
        ->and(explode("\n", MutantText::block($bare, TestNames::none())))->toHaveCount(4);
});

it('says why an ignored mutant is left out: its ignore\'s reason, or how it was ignored where it gives none', function (): void {
    [$ignored, $marked] = Overview::of(Verdicts::failing())->ignored();

    expect(MutantText::ignoredBecause($ignored))->toBe('Logging is asserted in the integration suite')
        ->and(MutantText::ignoredBecause($marked))->toBe('ignored by a native marker');
});

it('names the analyser that rejected a mutant, and the error it found', function (): void {
    $rejected = Judged::listed(Verdicts::everyJudgement())[11];

    expect(MutantText::block($rejected, TestNames::none()))
        ->toContain("\n    Rejected by phpstan: return.type: Method Log::id() should return string but returns int.\n")
        ->and(MutantText::block(Judged::listed(Verdicts::everyJudgement())[1], TestNames::none()))->not->toContain('Rejected by');
});

it('names the rejection in one plain line, whatever the analyser wrote', function (): void {
    $mutant = Verdicts::mutant('src/Log.php:14', 'CastString', MutatorFamily::Unwrap, Verdicts::diff('return (string) $id;', 'return $id;'))
        ->rejected(Rejection::by("php\u{202E}stan", Finding::error("x\e[31m", "red\e[0m\n::error::injected\u{200B}\r\n\tend")));
    $block = MutantText::block(JudgedMutant::of($mutant, MutantJudgement::KilledByStaticAnalysis), TestNames::none());

    expect($block)->toContain("\n    Rejected by phpstan: x[31m: red[0m ::error::injected end\n")
        ->and($block)->not->toContain("\e")
        ->and($block)->not->toContain("\n::")
        ->and($block)->not->toContain("\u{202E}");
});

it('starts no workflow command from a rejection that holds one, encoded or not', function (): void {
    $mutant = Verdicts::mutant('src/Log.php:14', 'CastString', MutatorFamily::Unwrap, Verdicts::diff('return (string) $id;', 'return $id;'))
        ->rejected(Rejection::by('phpstan', Finding::error("a,b:c%0A\n::error::code", "one, two: three%0A\n::error file=x,line=1::message\n##[error]older")));
    $lines = explode("\n", MutantText::block(JudgedMutant::of($mutant, MutantJudgement::KilledByStaticAnalysis), TestNames::none()));

    expect(array_values(array_filter($lines, static fn(string $line): bool => str_starts_with(ltrim($line), '::'))))->toBe([])
        ->and($lines)->toContain('    Rejected by phpstan: a,b:c%0A ::error::code: one, two: three%0A ::error file=x,line=1::message ##[error]older');
});

it('names the callee a removal may be deleted with in its block, and nothing of any other mutant', function (): void {
    $blocks = [];

    foreach (Removing::verdict()->trees()->mutants() as $judged) {
        $blocks[] = $judged instanceof JudgedMutant ? MutantText::block($judged, TestNames::none()) : '';
    }

    expect($blocks[0])->toContain(sprintf("\n%sRemovable: record()\n", MutantText::INDENT))
        ->and(implode("\n", array_slice($blocks, 1)))->not->toContain('Removable:');
});
