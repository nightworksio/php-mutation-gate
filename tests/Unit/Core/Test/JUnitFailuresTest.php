<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\JUnitFailures;
use NightWorksIO\MutationGate\Core\Test\TestId;

/**
 * The tests a JUnit log of these test cases names as failed, as the coverage map names them.
 *
 * @return list<string>
 */
function junitFailuresOf(string $cases): array
{
    $log = sprintf('<?xml version="1.0"?><testsuites><testsuite name="T">%s</testsuite></testsuites>', $cases);

    return array_map(static fn(TestId $test): string => $test->value(), [...JUnitFailures::of($log)]);
}

it('names each Pest test that failed or errored as the coverage map does, by the method Pest makes of its description', function (): void {
    expect(junitFailuresOf(<<<'XML'
        <testcase name="it adds two amounts" file="tests/MoneySpec.php::it adds two amounts" class="Tests\MoneySpec"><failure type="F">it adds two amountsFailed.</failure></testcase>
        <testcase name="it passes" file="tests/MoneySpec.php::it passes" class="Tests\MoneySpec"/>
        <testcase name="works_with a-dash" file="tests/MoneySpec.php::works_with a-dash" class="Tests\MoneySpec"><error type="E">x</error></testcase>
        XML))->toBe([
        'P\Tests\MoneySpec::__pest_evaluable_it_adds_two_amounts',
        'P\Tests\MoneySpec::__pest_evaluable_works__with_a_dash',
    ]);
});

it('names a data set\'s Pest test by its data set, numbered or named', function (): void {
    expect(junitFailuresOf(<<<'XML'
        <testcase name="it fails with data with data set &quot;(1)&quot;" file="tests/ASpec.php::it fails with data with data set &quot;(1)&quot;" class="Tests\ASpec"><failure>x</failure></testcase>
        <testcase name="it fails named with data set &quot;dataset &quot;one&quot;&quot;" file="tests/ASpec.php::it fails named with data set &quot;dataset &quot;one&quot;&quot;" class="Tests\ASpec"><failure>x</failure></testcase>
        XML))->toBe([
        'P\Tests\ASpec::__pest_evaluable_it_fails_with_data#(1)',
        'P\Tests\ASpec::__pest_evaluable_it_fails_named#dataset "one"',
    ]);
});

it('names a test method by its class and name, and a data set\'s by its number or name', function (): void {
    expect(junitFailuresOf(<<<'XML'
        <testcase name="testAdds" file="tests/MoneyTest.php" class="Tests\MoneyTest"><failure>x</failure></testcase>
        <testcase name="testAdds with data set #2" file="tests/MoneyTest.php" class="Tests\MoneyTest"><failure>x</failure></testcase>
        <testcase name="testAdds with data set &quot;small&quot;" file="tests/MoneyTest.php" class="Tests\MoneyTest"><error>x</error></testcase>
        XML))->toBe(['Tests\MoneyTest::testAdds', 'Tests\MoneyTest::testAdds#2', 'Tests\MoneyTest::testAdds#small']);
});

it('names none where the log names no failure, is not JUnit, or is empty, and reads a log that is no XML raising nothing', function (): void {
    $raised = [];
    set_error_handler(static function (int $level, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });
    $unread = [...JUnitFailures::of('not xml')];
    restore_error_handler();

    expect(junitFailuresOf('<testcase name="it passes" file="tests/A.php::it passes" class="Tests\A"/>'))->toBe([])
        ->and([...JUnitFailures::of(log: false)])->toBe([])
        ->and([...JUnitFailures::of('')])->toBe([])
        ->and($unread)->toBe([])
        ->and($raised)->toBe([]);
});

it('counts the test cases a log names and those that passed, neither failed, errored nor skipped, and none of a log it cannot read', function (): void {
    $log = sprintf('<?xml version="1.0"?><testsuites><testsuite name="T">%s</testsuite></testsuites>', <<<'XML'
        <testcase name="it passes" file="tests/A.php::it passes" class="Tests\A"/>
        <testcase name="it fails" file="tests/A.php::it fails" class="Tests\A"><failure>x</failure></testcase>
        <testcase name="it errs" file="tests/A.php::it errs" class="Tests\A"><error>x</error></testcase>
        <testcase name="it waits" file="tests/A.php::it waits" class="Tests\A"><skipped/></testcase>
        <testcase name="it passes too" file="tests/A.php::it passes too" class="Tests\A"><system-out>x</system-out></testcase>
        XML);

    expect([JUnitFailures::cases($log), JUnitFailures::passed($log)])->toBe([5, 2])
        ->and([JUnitFailures::cases(log: false), JUnitFailures::passed(log: false), JUnitFailures::cases(''), JUnitFailures::cases('<testsuites/>')])
        ->toBe([0, 0, 0, 0]);
});
