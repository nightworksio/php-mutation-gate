<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\KillingTests;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** What PHPUnit 13 prints for a run with these lists after its progress line. */
function phpunitPrinted(string $lists): string
{
    return sprintf("PHPUnit 13.3.4 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP 8.5.0\nConfiguration: /project/phpunit.xml\n\nF\n\nTime: 00:00.012, Memory: 10.00 MB\n\n%s\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.", $lists);
}

it('names the test that failed, as the coverage map names it', function (): void {
    $output = phpunitPrinted("There was 1 failure:\n\n1) Tests\\MoneySpec::addsTwoAmounts\nFailed asserting that -1 is identical to 5.\n\n/project/tests/MoneySpec.php:18\n");

    expect(KillingTests::in($output))->toEqual(TestIds::of(TestId::of('Tests\\MoneySpec::addsTwoAmounts')));
});

it('names every test that failed or met an error, each once', function (): void {
    $output = phpunitPrinted(
        "There were 2 errors:\n\n1) Tests\\A::one\nError: boom\n\n2) Tests\\B::two\nError: bang\n\n--\n\n"
        . "There was 1 failure:\n\n1) Tests\\A::one\nFailed.\n",
    );

    expect(KillingTests::in($output))->toEqual(TestIds::of(TestId::of('Tests\\A::one'), TestId::of('Tests\\B::two')));
});

it('names a data set\'s test by its data set, numbered or named, as the coverage map does', function (): void {
    $output = phpunitPrinted(
        "There were 2 failures:\n\n1) Tests\\MoneySpec::adds#0 with data (2, 3, 5)\nFailed.\n\n"
        . "2) Tests\\MoneySpec::adds@small amounts with data (1, 1, 2)\nFailed.\n",
    );

    expect(KillingTests::in($output))->toEqual(TestIds::of(
        TestId::of('Tests\\MoneySpec::adds#0'),
        TestId::of('Tests\\MoneySpec::adds#small amounts'),
    ));
});

it('names no test from any other list, or from a run that printed none', function (string $output): void {
    expect(KillingTests::in($output))->toEqual(TestIds::none());
})->with([
    'a risky test' => [phpunitPrinted("There was 1 risky test:\n\n1) Tests\\MoneySpec::adds\nThis test did not perform any assertions\n")],
    'a warning after a failure' => [phpunitPrinted("There was 1 PHPUnit warning:\n\n1) Tests\\MoneySpec::adds\nwarned\n")],
    'a crash' => ['PHP Fatal error:  Allowed memory size exhausted'],
    'a passing run' => ['OK (1 test, 1 assertion)'],
    'an error before any test' => [phpunitPrinted("There was 1 error:\n\n1) Tests\\MoneySpec\nError in setUpBeforeClass\n")],
]);

it('stops naming tests once a list of another kind begins', function (): void {
    $output = phpunitPrinted("There was 1 failure:\n\n1) Tests\\A::one\nFailed.\n\n--\n\nThere was 1 risky test:\n\n1) Tests\\B::two\nrisky\n");

    expect(KillingTests::in($output))->toEqual(TestIds::of(TestId::of('Tests\\A::one')));
});
