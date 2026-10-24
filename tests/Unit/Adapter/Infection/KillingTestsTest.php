<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\FailingIssues;
use NightWorksIO\MutationGate\Adapter\Infection\KillingTests;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** What PHPUnit 13 prints for a run with these lists after its progress line. */
function phpunitPrinted(string $lists): string
{
    return sprintf("PHPUnit 13.3.4 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP 8.5.0\nConfiguration: /project/phpunit.xml\n\nF\n\nTime: 00:00.012, Memory: 10.00 MB\n\n%s\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.", $lists);
}

/** The killers a run that printed this names, on a run that fails on these issues, among these tests the map knows. */
function killersIn(string $output, FailingIssues $failing = new FailingIssues(), string ...$known): TestIds
{
    return KillingTests::of($failing, TestIds::of(...array_map(TestId::of(...), $known)))->in($output);
}

/** @return list<string> */
function killerNames(TestIds $tests): array
{
    return array_map(static fn(TestId $test): string => $test->value(), [...$tests]);
}

it('names the test that failed, as the coverage map names it', function (): void {
    $output = phpunitPrinted("There was 1 failure:\n\n1) Tests\\MoneySpec::addsTwoAmounts\nFailed asserting that -1 is identical to 5.\n\n/project/tests/MoneySpec.php:18\n");

    expect(killersIn($output))->toEqual(TestIds::of(TestId::of('Tests\\MoneySpec::addsTwoAmounts')));
});

it('names every test that failed or met an error, each once', function (): void {
    $output = phpunitPrinted(
        "There were 2 errors:\n\n1) Tests\\A::one\nError: boom\n\n2) Tests\\B::two\nError: bang\n\n--\n\n"
        . "There was 1 failure:\n\n1) Tests\\A::one\nFailed.\n",
    );

    expect(killersIn($output))->toEqual(TestIds::of(TestId::of('Tests\\A::one'), TestId::of('Tests\\B::two')));
});

it('names a data set\'s test by its data set, numbered or named, as the coverage map does', function (): void {
    $output = phpunitPrinted(
        "There were 2 failures:\n\n1) Tests\\MoneySpec::adds#0 with data (2, 3, 5)\nFailed.\n\n"
        . "2) Tests\\MoneySpec::adds@small amounts with data (1, 1, 2)\nFailed.\n",
    );

    expect(killersIn($output))->toEqual(TestIds::of(
        TestId::of('Tests\\MoneySpec::adds#0'),
        TestId::of('Tests\\MoneySpec::adds#small amounts'),
    ));
});

it('names the risky tests, and the tests PHPUnit warned of, where no test failed', function (): void {
    $output = phpunitPrinted(
        "There were 2 risky tests:\n\n1) Tests\\A::one\nTest code or tested code did not remove its own error handlers\n\n/project/tests/A.php:72\n\n"
        . "2) Tests\\A::two#1 with data (3)\nThis test did not perform any assertions\n\n--\n\n"
        . "1 test triggered 1 PHPUnit warning:\n\n1) Tests\\B::three\nwarned\n\n--\n\n"
        . "There was 1 PHPUnit warning:\n\n1) Tests\\B::four\nwarned\n",
    );

    expect(killerNames(killersIn($output)))->toBe(['Tests\\A::one', 'Tests\\A::two#1', 'Tests\\B::three', 'Tests\\B::four']);
});

it('names the tests a warning lists as triggering it, a data set\'s by its data set, however often each did', function (): void {
    $output = phpunitPrinted(
        "1 test triggered 2 PHP warnings:\n\n1) /project/src/Loader.php:319\nUndefined property: Name::\$expr\n\nTriggered by:\n\n"
        . "* Tests\\LoaderTest::loadsByConst\n  /project/tests/LoaderTest.php:241\n\n"
        . "* Tests\\LoaderTest::loads#named set (2 times)\n  /project/tests/LoaderTest.php:250\n\n"
        . "--\n\n2 tests triggered 1 warning:\n\n1) /project/src/Loader.php:12\nuser warning\n\nTriggered by:\n\n* Tests\\Other::warns\n",
    );

    expect(killerNames(killersIn($output)))->toBe(['Tests\\LoaderTest::loadsByConst', 'Tests\\LoaderTest::loads#named set', 'Tests\\Other::warns']);
});

it('names, for a warning raised in a test itself, each data set the map knows of the test method its line is in', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'tests/TrapTest.php', <<<'PHP_WRAP'
    <?php
    namespace Tests;
    final class TrapTest extends \PHPUnit\Framework\TestCase
    {
        public function testTraps(): void
        {
            file_get_contents(__FILE__);
        }
    
        public function testRestores(): void
        {
            file_exists(__FILE__);
        }
    }
    PHP_WRAP);
    $output = phpunitPrinted(sprintf(
        "1 test triggered 2 PHP warnings:\n\n1) %1\$s/tests/TrapTest.php:7\nfile_get_contents(): Failed to open stream\n\n"
        . "2) %1\$s/tests/TrapTest.php:12\nanother\n\nTriggered by:\n\n* Tests\\Other::warns\n",
        $root,
    ));

    expect(killerNames(killersIn($output, new FailingIssues(), 'Tests\\TrapTest::testTraps#0', 'Tests\\TrapTest::testTraps#one', 'Tests\\TrapTest::testRestores', 'Tests\\TrapTestOther::testTraps')))
        ->toBe(['Tests\\Other::warns', 'Tests\\TrapTest::testTraps#0', 'Tests\\TrapTest::testTraps#one'])
        ->and(killerNames(killersIn($output, new FailingIssues(), 'Tests\\TrapTest::testTraps')))->toBe(['Tests\\Other::warns', 'Tests\\TrapTest::testTraps']);
});

it('names the test a warning was raised in, where it is the last the run printed or another list follows it', function (string $after): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'tests/TrapTest.php', "<?php\nnamespace Tests;\nfinal class TrapTest\n{\n    public function testTraps(): void\n    {\n    }\n}\n");
    $output = sprintf("1 test triggered 1 PHP warning:\n\n1) %s/tests/TrapTest.php:6\nwarned\n%s", $root, $after);

    expect(killerNames(killersIn($output, new FailingIssues(), 'Tests\\TrapTest::testTraps')))->toBe(['Tests\\TrapTest::testTraps']);
})->with([
    'the last' => [''],
    'another list after it' => ["\n--\n\nThere was 1 skipped test:\n\n1) Tests\\B::two\n"],
]);

it('names no test for a warning raised in a test where the file is gone, its line is in no method, or the map knows no test of it', function (string $place, string $known): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'tests/TrapTest.php', "<?php\nnamespace Tests;\nfinal class TrapTest\n{\n    public function testTraps(): void\n    {\n    }\n}\n");
    $output = phpunitPrinted(sprintf("1 test triggered 1 PHP warning:\n\n1) %s/%s\nwarned\n", $root, $place));

    expect(killersIn($output, new FailingIssues(), $known, '.phpt/test.phpt'))->toEqual(TestIds::none());
})->with([
    'a file that is gone' => ['tests/GoneTest.php:6', 'Tests\\TrapTest::testTraps'],
    'a line in no method' => ['tests/TrapTest.php:3', 'Tests\\TrapTest::testTraps'],
    'a method the map does not know' => ['tests/TrapTest.php:6', 'Tests\\TrapTest::testOther'],
]);

it('names only the tests that failed where one did, whatever issues the others raised', function (): void {
    $output = phpunitPrinted(
        "There was 1 risky test:\n\n1) Tests\\B::two\nrisky\n\n--\n\n"
        . "There was 1 failure:\n\n1) Tests\\A::one\nFailed.\n\n--\n\n"
        . "1 test triggered 1 PHP warning:\n\n1) /project/src/A.php:3\nwarned\n\nTriggered by:\n\n* Tests\\C::three\n",
    );

    expect(killerNames(killersIn($output)))->toBe(['Tests\\A::one']);
});

it('names no test whose issues do not fail the run', function (FailingIssues $failing, string $lists): void {
    expect(killersIn(phpunitPrinted($lists), $failing))->toEqual(TestIds::none());
})->with([
    'a risky test, risky tests passing' => [fn(): FailingIssues => new FailingIssues(risky: false), "There was 1 risky test:\n\n1) Tests\\A::one\nrisky\n"],
    'a warning, warnings passing' => [fn(): FailingIssues => new FailingIssues(warning: false), "1 test triggered 1 PHP warning:\n\n1) /project/src/A.php:3\nwarned\n\nTriggered by:\n\n* Tests\\A::one\n"],
    'a user warning, warnings passing' => [fn(): FailingIssues => new FailingIssues(warning: false), "1 test triggered 1 warning:\n\n1) /project/src/A.php:3\nwarned\n\nTriggered by:\n\n* Tests\\A::one\n"],
    'a warning of PHPUnit\'s, those passing' => [fn(): FailingIssues => new FailingIssues(phpunitWarning: false), "1 test triggered 1 PHPUnit warning:\n\n1) Tests\\A::one\nwarned\n"],
]);

it('names no test from any other list, or from a run that printed none', function (string $output): void {
    expect(killersIn($output))->toEqual(TestIds::none());
})->with([
    'a deprecation' => [fn(): string => phpunitPrinted("1 test triggered 1 PHP deprecation:\n\n1) /project/src/A.php:3\ndeprecated\n\nTriggered by:\n\n* Tests\\A::one\n")],
    'an error PHP\'s E_USER_ERROR raised' => [fn(): string => phpunitPrinted("1 test triggered 1 error:\n\n1) Tests\\A::one\nraised\n")],
    'a warning outside any test' => [fn(): string => phpunitPrinted("There was 1 PHP warning triggered outside of tests:\n\n1) Tests\\A::one\nwarned\n")],
    'a crash' => ['PHP Fatal error:  Allowed memory size exhausted'],
    'a passing run' => ['OK (1 test, 1 assertion)'],
    'an error before any test' => [fn(): string => phpunitPrinted("There was 1 error:\n\n1) Tests\\MoneySpec\nError in setUpBeforeClass\n")],
]);

it('stops naming tests once a list of another kind begins', function (): void {
    $output = phpunitPrinted("There was 1 failure:\n\n1) Tests\\A::one\nFailed.\n\n--\n\nThere were 2 skipped tests:\n\n1) Tests\\B::two\nskipped\n");

    expect(killersIn($output))->toEqual(TestIds::of(TestId::of('Tests\\A::one')));
});
