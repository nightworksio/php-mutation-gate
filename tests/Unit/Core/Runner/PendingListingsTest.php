<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\PendingListings;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

it('takes what each set of suites listed only once asked, and stops the listings when stopped', function (): void {
    $takes = 0;
    $stops = 0;
    $listed = [TestListing::of(TestIds::of(TestId::of('Tests\MoneyTest::adds'))), CannotJudge::because('broken')];
    $pending = PendingListings::taking(
        static function () use (&$takes, $listed): array {
            $takes++;

            return $listed;
        },
        static function () use (&$stops): void {
            $stops++;
        },
    );

    $pending->stop();
    $before = $takes;

    expect($before)->toBe(0)
        ->and($pending->taken())->toBe($listed)
        ->and([$takes, $stops])->toBe([1, 1]);
});

it('holds listings already listed, which nothing stops', function (): void {
    $listed = PendingListings::listed(TestListing::none(), CannotJudge::because('broken'));
    $listed->stop();

    expect($listed->taken())->toEqual([TestListing::none(), CannotJudge::because('broken')]);
});
