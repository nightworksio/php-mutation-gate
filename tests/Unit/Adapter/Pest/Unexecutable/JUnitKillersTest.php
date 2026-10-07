<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\JUnitKillers;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The tests a JUnit log of these test cases names as killers, as the coverage map names them.
 *
 * @return list<string>
 */
function junitKillersOf(string $cases): array
{
    $log = sprintf('%s/junit.xml', Scratch::directory());
    file_put_contents($log, sprintf('<?xml version="1.0"?><testsuites><testsuite name="T">%s</testsuite></testsuites>', $cases));

    return array_map(static fn(TestId $test): string => $test->value(), [...JUnitKillers::in($log)]);
}

it('names each Pest test that failed or errored as the coverage map does, by the method Pest makes of its description', function (): void {
    expect(junitKillersOf(<<<'XML'
        <testcase name="it adds two amounts" file="tests/MoneySpec.php::it adds two amounts" class="Tests\MoneySpec"><failure type="F">it adds two amountsFailed.</failure></testcase>
        <testcase name="it passes" file="tests/MoneySpec.php::it passes" class="Tests\MoneySpec"/>
        <testcase name="works_with a-dash" file="tests/MoneySpec.php::works_with a-dash" class="Tests\MoneySpec"><error type="E">x</error></testcase>
        XML))->toBe([
        'P\Tests\MoneySpec::__pest_evaluable_it_adds_two_amounts',
        'P\Tests\MoneySpec::__pest_evaluable_works__with_a_dash',
    ]);
});

it('names a data set\'s Pest test by its data set, numbered or named', function (): void {
    expect(junitKillersOf(<<<'XML'
        <testcase name="it fails with data with data set &quot;(1)&quot;" file="tests/ASpec.php::it fails with data with data set &quot;(1)&quot;" class="Tests\ASpec"><failure>x</failure></testcase>
        <testcase name="it fails named with data set &quot;dataset &quot;one&quot;&quot;" file="tests/ASpec.php::it fails named with data set &quot;dataset &quot;one&quot;&quot;" class="Tests\ASpec"><failure>x</failure></testcase>
        XML))->toBe([
        'P\Tests\ASpec::__pest_evaluable_it_fails_with_data#(1)',
        'P\Tests\ASpec::__pest_evaluable_it_fails_named#dataset "one"',
    ]);
});

it('names a test method by its class and name, and a data set\'s by its number or name', function (): void {
    expect(junitKillersOf(<<<'XML'
        <testcase name="testAdds" file="tests/MoneyTest.php" class="Tests\MoneyTest"><failure>x</failure></testcase>
        <testcase name="testAdds with data set #2" file="tests/MoneyTest.php" class="Tests\MoneyTest"><failure>x</failure></testcase>
        <testcase name="testAdds with data set &quot;small&quot;" file="tests/MoneyTest.php" class="Tests\MoneyTest"><error>x</error></testcase>
        XML))->toBe(['Tests\MoneyTest::testAdds', 'Tests\MoneyTest::testAdds#2', 'Tests\MoneyTest::testAdds#small']);
});

it('names none where the log names no failure, is not JUnit, or is not there', function (): void {
    $missing = sprintf('%s/none.xml', Scratch::directory());
    $broken = sprintf('%s/broken.xml', Scratch::directory());
    file_put_contents($broken, 'not xml');

    expect(junitKillersOf('<testcase name="it passes" file="tests/A.php::it passes" class="Tests\A"/>'))->toBe([])
        ->and([...JUnitKillers::in($missing)])->toBe([])
        ->and([...JUnitKillers::in($broken)])->toBe([]);
});
