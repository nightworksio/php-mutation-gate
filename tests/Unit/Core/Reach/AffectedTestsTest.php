<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Tests\Support\Affected;

it('lists every test file of the suite, each with all its tests, for a reason', function (): void {
    $every = AffectedTests::every(Affected::places(), Reason::that('No map.'));

    expect([$every->isEvery(), $every->listsNone(), Affected::listed($every)])->toBe([true, false, [
        ['tests/MoneyTest.php', ['MoneyTest::adds', 'MoneyTest::adds nothing'], ['No map.']],
        ['tests/PriceTest.php', ['PriceTest::totals'], ['No map.']],
        ['tests/RateTest.php', ['RateTest::rates'], ['No map.']],
    ]]);
});

it('lists nothing at first', function (): void {
    $none = AffectedTests::none(Affected::places());

    expect([$none->listsNone(), $none->isEvery(), $none->tests(), [...$none->unreached()], count($none->nothingBecause())])
        ->toBe([true, false, [], [], 0]);
});

it('lists a test file once, with every test and reason that reached it', function (): void {
    $affected = AffectedTests::none(Affected::places())
        ->reaching(Path::of('tests/MoneyTest.php'), Affected::ids('MoneyTest::adds'), Reason::that('One.'))
        ->reaching(Path::of('tests/MoneyTest.php'), Affected::ids('MoneyTest::adds nothing'), Reason::that('Two.'));

    expect(Affected::listed($affected))->toBe([
        ['tests/MoneyTest.php', ['MoneyTest::adds', 'MoneyTest::adds nothing'], ['One.', 'Two.']],
    ]);
});

it('adds what another answer lists, leaves and gives as reasons', function (): void {
    $one = AffectedTests::none(Affected::places())
        ->wholly(Path::of('tests/RateTest.php'), Reason::that('Rate.'))
        ->because(Reason::that('Nothing.'));
    $two = AffectedTests::none(Affected::places())
        ->wholly(Path::of('tests/RateTest.php'), Reason::that('Again.'))
        ->leaving(Path::of('src/Lone.php'), Reason::that('Left.'))
        ->all(Reason::that('All.'));

    $both = $one->and($two);

    expect([
        Affected::texts($both->everyBecause()),
        Affected::texts($both->nothingBecause()),
        array_map(static fn(Path $path): string => $path->value(), [...$both->unreached()]),
        Affected::listed($both)[2],
    ])->toBe([
        ['All.'],
        ['Nothing.', 'Left.'],
        ['src/Lone.php'],
        ['tests/RateTest.php', ['RateTest::rates'], ['Rate.', 'Again.', 'All.']],
    ]);
});
