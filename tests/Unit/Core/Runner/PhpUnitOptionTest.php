<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;

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

it('keeps a run to its suites\' tests with --testsuite, comma-separated, and to none where it runs every suite', function (): void {
    expect(PhpUnitOption::inSuites(Suites::named(SuiteName::of('unit'))))->toBe(['--testsuite=unit'])
        ->and(PhpUnitOption::inSuites(Suites::named(SuiteName::of('unit'), SuiteName::of('Contract Tests'))))->toBe(['--testsuite=unit,Contract Tests'])
        ->and(PhpUnitOption::inSuites(Suites::all()))->toBe([]);
});

it('keeps PHPUnit\'s caches in a directory with --cache-directory and the directory after =', function (): void {
    expect(PhpUnitOption::caching('/p/.mutation-gate/phpunit-cache'))->toBe('--cache-directory=/p/.mutation-gate/phpunit-cache');
});

it('keeps PHPUnit\'s caches in a directory after the other arguments, dropping a cache directory they name either way', function (): void {
    expect(PhpUnitOption::cachedIn('/g', []))->toBe(['--cache-directory=/g'])
        ->and(PhpUnitOption::cachedIn('/g', ['--testsuite=unit', 'tests/Unit']))->toBe(['--testsuite=unit', 'tests/Unit', '--cache-directory=/g'])
        ->and(PhpUnitOption::cachedIn('/g', ['--testsuite=unit', '--cache-directory=.cache', '--group=slow']))->toBe(['--testsuite=unit', '--group=slow', '--cache-directory=/g'])
        ->and(PhpUnitOption::cachedIn('/g', ['--cache-directory', '.cache', '--group', 'slow']))->toBe(['--group', 'slow', '--cache-directory=/g'])
        ->and(PhpUnitOption::cachedIn('/g', ['--cache-directory-x', '--cache-result']))->toBe(['--cache-directory-x', '--cache-result', '--cache-directory=/g']);
});
