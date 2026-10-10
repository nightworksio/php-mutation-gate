<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\Holder;
use NightWorksIO\MutationGate\Core\Hold\Holding;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

it('is a path a group holds, named by the group', function (): void {
    $holding = Holding::byGroup('src/Kernel.php', Group::named('holds:src/Kernel.php'));

    expect($holding->declared())->toBe('src/Kernel.php')
        ->and($holding->by())->toEqual(Group::named('holds:src/Kernel.php'))
        ->and($holding->written())->toBe('holds:src/Kernel.php');
});

it('is a path a #[Holds] holds, named by the attribute and what it stands on', function (): void {
    $holding = Holding::byAttribute('src/Kernel.php', Holder::of('Tests\KernelTest::testBoots'));

    expect($holding->declared())->toBe('src/Kernel.php')
        ->and($holding->by())->toEqual(Holder::of('Tests\KernelTest::testBoots'))
        ->and($holding->written())->toBe("#[Holds('src/Kernel.php')] on Tests\\KernelTest::testBoots");
});

it('finds its tests in a listing: those of its group, or those the class or method #[Holds] stands on', function (): void {
    $listing = TestListing::of(TestIds::of(
        TestId::of('Tests\KernelTest::testBoots'),
        TestId::of('Tests\KernelTest::testBoots#twice'),
        TestId::of('Tests\KernelTest::testBootsAgain'),
        TestId::of('Tests\KernelTestCase::testStops'),
        TestId::of('Other\Tests\KernelTest::testRuns'),
    ))->grouping(Group::named('holds:src/Kernel.php'), TestIds::of(TestId::of('Tests\KernelTest::testBootsAgain')));

    expect(Holding::byGroup('src/Kernel.php', Group::named('holds:src/Kernel.php'))->testsIn($listing))
        ->toEqual(TestIds::of(TestId::of('Tests\KernelTest::testBootsAgain')))
        ->and(Holding::byAttribute('src/Kernel.php', Holder::of('Tests\KernelTest::testBoots'))->testsIn($listing))
        ->toEqual(TestIds::of(TestId::of('Tests\KernelTest::testBoots'), TestId::of('Tests\KernelTest::testBoots#twice')))
        ->and(Holding::byAttribute('src/Kernel.php', Holder::of('Tests\KernelTest'))->testsIn($listing))
        ->toEqual(TestIds::of(
            TestId::of('Tests\KernelTest::testBoots'),
            TestId::of('Tests\KernelTest::testBoots#twice'),
            TestId::of('Tests\KernelTest::testBootsAgain'),
        ))
        ->and(Holding::byGroup('src/Http', Group::named('holds:src/Http'))->testsIn($listing))->toEqual(TestIds::none());
});
