<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\Version;

it('leaves the test run history as it was with --do-not-cache-result only before PHPUnit 13.3', function (string $version, PhpUnitOption $option): void {
    expect(PhpUnitOption::leavingHistoryOf(Version::of('phpunit/phpunit', $version, 'abc')))->toBe($option);
})->with([
    'the last before it' => ['13.2.99', PhpUnitOption::DoNotCacheResult],
    'a tag before it' => ['v13.2.0', PhpUnitOption::DoNotCacheResult],
    'the first with it' => ['13.3.0', PhpUnitOption::DoNotRecordTestRunHistory],
    'a tag with it' => ['v13.3.0', PhpUnitOption::DoNotRecordTestRunHistory],
    'a branch, taken as the newest' => ['13.2.x-dev', PhpUnitOption::DoNotRecordTestRunHistory],
]);

it('leaves the test run history as it was with the history\'s own option where no version is known', function (): void {
    expect(PhpUnitOption::leavingHistoryOf(NotGiven::value()))->toBe(PhpUnitOption::DoNotRecordTestRunHistory)
        ->and(PhpUnitOption::DoNotRecordTestRunHistory->value)->toBe('--do-not-record-test-run-history');
});
