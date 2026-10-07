<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A control of a file by these tests, allowed this long.
 *
 * @param list<string> $tests
 */
function controlOf(string $file, array $tests, float $limit): Control
{
    return Control::of(
        Path::of($file),
        TestIds::of(...array_map(TestId::of(...), $tests)),
        Seconds::of($limit),
    );
}

it('holds the file it serves unmutated, the tests it runs in their order, and the limit it is allowed', function (): void {
    $control = controlOf('src/Money.php', ['MoneyTest::b', 'MoneyTest::a'], 5.0);

    expect($control->file())->toEqual(Path::of('src/Money.php'))
        ->and(array_map(static fn(TestId $test): string => $test->value(), [...$control->tests()]))->toBe(['MoneyTest::b', 'MoneyTest::a'])
        ->and($control->limit())->toEqual(Seconds::of(5.0));
});

it('is told from another control by its file, its tests in their order and its limit, and by nothing else', function (Control $other, bool $same): void {
    expect(controlOf('src/Money.php', ['MoneyTest::a', 'MoneyTest::b'], 5.0)->key() === $other->key())->toBe($same);
})->with([
    'the same control' => [controlOf('src/Money.php', ['MoneyTest::a', 'MoneyTest::b'], 5.0), true],
    'another file' => [controlOf('src/Tax.php', ['MoneyTest::a', 'MoneyTest::b'], 5.0), false],
    'another test' => [controlOf('src/Money.php', ['MoneyTest::a', 'MoneyTest::c'], 5.0), false],
    'the tests in another order' => [controlOf('src/Money.php', ['MoneyTest::b', 'MoneyTest::a'], 5.0), false],
    'fewer tests' => [controlOf('src/Money.php', ['MoneyTest::a'], 5.0), false],
    'another limit' => [controlOf('src/Money.php', ['MoneyTest::a', 'MoneyTest::b'], 6.0), false],
    'one test whose id holds the others\' joined' => [controlOf('src/Money.php', ['MoneyTest::a MoneyTest::b'], 5.0), false],
]);
