<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\Assertion;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Assertion\Weakness;
use NightWorksIO\MutationGate\Core\Assertion\WeakTest;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use NightWorksIO\MutationGate\Tests\Support\Weakly;

it('names the test files of the tests judging a survivor a value would kill, where the runner named them', function (): void {
    expect([...Weakness::testFiles(Weakly::trees(Weakly::literal(), Weakly::boundary()), Weakly::matrix())])
        ->toEqual([Path::of(Weakly::TESTS)])
        ->and([...Weakness::testFiles(Weakly::trees(Weakly::boundary()), Weakly::matrix())])->toBe([]);
});

it('finds the weak tests that let a survivor through, what they assert, and the function around it', function (): void {
    $finding = Weakness::findings(Weakly::trees(Weakly::literal()), Weakly::matrix(), Weakly::files())->of(Weakly::literal()->mutant()->id());
    $weak = $finding instanceof WeaklyAsserted ? [...$finding->tests()] : [];

    expect($finding)->toBeInstanceOf(WeaklyAsserted::class)
        ->and(array_map(static fn(WeakTest $test): string => $test->test()->value(), $weak))->toBe([Weakly::weakTest()->value()])
        ->and(array_map(static fn(Assertion $assertion): string => $assertion->written(), [...$weak[0]->assertions()]))
        ->toBe(['->toBeBool()', '->not->toBeNull()'])
        ->and($finding instanceof WeaklyAsserted ? $finding->function() : '')->toBe('fits');
});

it('finds nothing of a survivor no weak test judges, one a value may not see, or one not survived', function (JudgedMutant $survivor, TestId ...$tests): void {
    expect(Weakness::findings(Weakly::trees($survivor), Weakly::matrix(...$tests), Weakly::files())->of($survivor->mutant()->id()))
        ->toEqual(NoFinding::survivor());
})->with([
    'judged by a strong test alone' => [Weakly::literal(), Weakly::strongTest()],
    'judged by a test with no file named' => [Weakly::literal(), Weakly::unnamedTest()],
    'judged by no test' => [Weakly::survivor(3, 'FalseValue', MutatorFamily::Literal, Verdicts::diff('return false;', 'return true;'))],
    'a Boundary' => [Weakly::boundary()],
    'an uncovered Literal' => [Weakly::survivor(11, 'FalseValue', MutatorFamily::Literal, Verdicts::diff('return false;', 'return true;'), MutantJudgement::Uncovered)],
]);

it('reads the test from its file, and names no function where the survivor\'s own file is not read', function (): void {
    $tests = ByPath::none()->with(Path::of(Weakly::TESTS), Contents::of(Weakly::TEST_FILE));
    $finding = Weakness::findings(Weakly::trees(Weakly::literal()), Weakly::matrix(), $tests)->of(Weakly::literal()->mutant()->id());

    expect($finding instanceof WeaklyAsserted ? $finding->function() : '')->toEqual(Nameless::code())
        ->and(Weakness::findings(Weakly::trees(Weakly::literal()), Weakly::matrix(), ByPath::none())->of(Weakly::literal()->mutant()->id()))
        ->toEqual(NoFinding::survivor());
});
