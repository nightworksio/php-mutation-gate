<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

it('holds the tests it lists, and none where it lists none', function (): void {
    $tests = TestIds::of(TestId::of('MoneyTest::adds'));

    expect(TestListing::of($tests)->tests())->toEqual($tests)
        ->and(TestListing::none()->tests())->toEqual(TestIds::none())
        ->and(TestListing::none()->groups())->toEqual(Groups::none());
});

it('lists each group in the order it was given, with its tests, and none for a group it does not list', function (): void {
    $kernel = TestIds::of(TestId::of('KernelTest::boots'));
    $slow = TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('KernelTest::boots'));
    $listing = TestListing::none()
        ->grouping(Group::named('holds:src/Kernel.php'), $kernel)
        ->grouping(Group::named('slow'), $slow);

    expect([...$listing->groups()])->toEqual([Group::named('holds:src/Kernel.php'), Group::named('slow')])
        ->and($listing->inGroup(Group::named('slow')))->toEqual($slow)
        ->and($listing->inGroup(Group::named('holds:src/Kernel.php')))->toEqual($kernel)
        ->and($listing->inGroup(Group::named('fast')))->toEqual(TestIds::none());
});

it('lists a group whose name reads as a number by that name', function (): void {
    $tests = TestIds::of(TestId::of('MoneyTest::adds'));
    $listing = TestListing::none()->grouping(Group::named('1234'), $tests);

    expect([...$listing->groups()])->toEqual([Group::named('1234')])
        ->and($listing->inGroup(Group::named('1234')))->toEqual($tests);
});

it('keeps the tests a group was given last, where it is given twice', function (): void {
    $later = TestIds::of(TestId::of('KernelTest::boots'));
    $listing = TestListing::none()
        ->grouping(Group::named('slow'), TestIds::of(TestId::of('MoneyTest::adds')))
        ->grouping(Group::named('slow'), $later);

    expect($listing->inGroup(Group::named('slow')))->toEqual($later)
        ->and(count($listing->groups()))->toBe(1);
});
