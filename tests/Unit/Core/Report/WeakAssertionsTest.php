<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\Assertion;
use NightWorksIO\MutationGate\Core\Assertion\AssertionKind;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Assertion\Weakness;
use NightWorksIO\MutationGate\Core\Assertion\WeakTest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Report\TestsReport;
use NightWorksIO\MutationGate\Core\Report\WeakAssertions;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use NightWorksIO\MutationGate\Tests\Support\Weakly;

/** The Cart's two survivors of line 11, as the gate finds them: both let through by `it fits`. */
function weaklyFound(): Verdict
{
    $trees = Weakly::trees(
        Weakly::literal(),
        Weakly::survivor(11, 'ReturnNull', MutatorFamily::ReturnValue, Verdicts::diff('return false;', 'return null;')),
        Weakly::boundary(),
    );

    return Verdict::of($trees->found(Weakness::findings($trees, Weakly::matrix(), Weakly::sources(), Weakly::tests())))->withMatrix(Weakly::matrix());
}

/** A survivor outside any function, let through by both data set rows of a PHPUnit test. */
function weaklyOutside(): Verdict
{
    return Verdict::of(Weakly::trees(Weakly::literal()->found(weaklyOutsideFinding())))->withMatrix(Weakly::matrix());
}

/** Both data set rows of a PHPUnit test, weak outside any function. */
function weaklyOutsideFinding(): WeaklyAsserted
{
    $weak = static fn(string $row): WeakTest => WeakTest::of(
        TestId::of(sprintf('Tests\\OrderTest::testTotals%s', $row)),
        TestName::in(Path::of('tests/OrderTest.php'), 'testTotals'),
        Assertion::of('assertNotNull', AssertionKind::Existence, AssertionStyle::PhpUnit),
    );

    return WeaklyAsserted::by(Nameless::code(), $weak('#0'), $weak('#1'));
}

it('lists each weak test once, by its name, with its assertions, the survivors it let through, and what to assert in its style', function (): void {
    expect(WeakAssertions::of(weaklyFound()))->toBe([[
        'test' => 'tests/CartTest.php::it fits',
        'assertions' => ['->toBeBool()', '->not->toBeNull()'],
        'survivors' => [Weakly::literal()->mutant()->id()->value(), Weakly::survivor(11, 'ReturnNull', MutatorFamily::ReturnValue, Verdicts::diff('return false;', 'return null;'))->mutant()->id()->value()],
        'assert' => 'expect(fits(…))->toBe(<expected>)',
    ]]);
});

it('suggests a PHPUnit assertion to a PHPUnit test, once for all its rows, on a result where no function is around the survivor', function (): void {
    expect(WeakAssertions::of(weaklyOutside()))->toBe([[
        'test' => 'tests/OrderTest.php::testTotals',
        'assertions' => ['assertNotNull'],
        'survivors' => [Weakly::literal()->mutant()->id()->value()],
        'assert' => Weakly::PHPUNIT_OUTSIDE,
    ]])->and(Weakly::literal()->found(weaklyOutsideFinding())->hint()->text())
        ->toContain('`tests/OrderTest.php::testTotals` asserts only `assertNotNull`, which');
});

it('writes the section as Markdown', function (): void {
    expect(WeakAssertions::markdown(weaklyOutside()))->toBe([
        '## Asserts only existence or shape (1)',
        WeakAssertions::MEANS,
        implode("\n", [
            '| Test | It asserts | Survivors it let through | Assert instead |',
            '|---|---|---|---|',
            sprintf(
                '| <code>tests/OrderTest.php::testTotals</code> | <code>assertNotNull</code> | <code>%s</code> | <code>$this-&gt;assertSame(&lt;expected&gt;, …)</code> |',
                Weakly::literal()->mutant()->id()->value(),
            ),
        ]),
    ])->and(WeakAssertions::markdown(Verdicts::passing()))->toBe(['## Asserts only existence or shape (0)', 'None.']);
});

it('writes the section as the console prints it', function (): void {
    expect(WeakAssertions::text(weaklyOutside()))->toBe([
        '',
        'Asserts only existence or shape (1)',
        sprintf('  tests/OrderTest.php::testTotals  asserts assertNotNull  let through %s  assert instead %s', Weakly::literal()->mutant()->id()->value(), Weakly::PHPUNIT_OUTSIDE),
    ])->and(WeakAssertions::text(Verdicts::passing()))->toBe(['', 'Asserts only existence or shape (0)', '  None.']);
});

it('is the tests report\'s third section in every form, as its schema describes', function (): void {
    $json = json_decode(TestsReport::json(weaklyFound()), associative: true);

    expect(Schema::errors(TestsReport::json(weaklyFound()), Schema::at('resources/tests.schema.json')))->toBe([])
        ->and(is_array($json) ? $json['weak'] : [])->toBe(WeakAssertions::of(weaklyFound()))
        ->and(TestsReport::markdown(weaklyFound()))->toContain(implode("\n\n", WeakAssertions::markdown(weaklyFound())))
        ->and(TestsReport::text(weaklyFound()))->toContain(implode("\n", WeakAssertions::text(weaklyFound())));
});
