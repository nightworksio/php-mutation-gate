<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\IssueKillers;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitResults;
use PHPUnit\Framework\TestCase;
use PHPUnit\TestRunner\TestResult\TestResult;
use PHPUnit\TextUI\Configuration\Configuration;

it('names each test that raised an issue of a kind the run fails on, each once, and none of a kind it does not', function (): void {
    $money = PhpUnitEvents::test(TestCase::class, 'money');
    $tax = PhpUnitEvents::test(TestCase::class, 'tax');
    $result = PhpUnitResults::result(
        warnings: [PhpUnitResults::issue($money), PhpUnitResults::issue($money)],
        notices: [PhpUnitResults::issue($tax)],
        risky: [$money->id() => []],
    );

    expect(IssueKillers::of(PhpUnitResults::configured('--fail-on-warning', '--fail-on-risky'), $result))->toBe([$money->id()])
        ->and(IssueKillers::of(PhpUnitResults::configured('--fail-on-warning', '--fail-on-notice'), $result))
        ->toBe([$money->id(), $tax->id()]);
});

it('names none where the run passes, or a test failed or errored, whose killer is named as it fails', function (): void {
    $money = PhpUnitEvents::test(TestCase::class, 'money');
    $warned = PhpUnitResults::result(warnings: [PhpUnitResults::issue($money)]);
    $errored = PhpUnitResults::result(warnings: [PhpUnitResults::issue($money)], phpunitErrors: [$money->id() => []]);

    expect(IssueKillers::of(PhpUnitResults::configured('--do-not-fail-on-warning'), $warned))->toBe([])
        ->and(IssueKillers::of(PhpUnitResults::configured('--fail-on-warning'), $errored))->toBe([]);
});

it('names none where PHPUnit passes the run, though a kind it fails on was raised', function (): void {
    $money = PhpUnitEvents::test(TestCase::class, 'money');
    $direct = PhpUnitResults::result(
        phpDeprecations: [PhpUnitResults::issue($money)],
        byTrigger: ['self' => 0, 'direct' => 1, 'indirect' => 0, 'unknown' => 0],
    );

    expect(IssueKillers::of(PhpUnitResults::configured('--fail-on-self-deprecation'), $direct))->toBe([]);
});

it('names none for an issue raised outside any test, though it fails the run', function (): void {
    $result = PhpUnitResults::result(runnerWarnings: [PhpUnitResults::runnerWarning()]);

    expect(IssueKillers::of(PhpUnitResults::configured('--fail-on-phpunit-warning'), $result))->toBe([]);
});

it('names the killers of the run whose configuration and result it reads, once that run has ended', function (): void {
    $money = PhpUnitEvents::test(TestCase::class, 'money');
    $killers = IssueKillers::reading(
        static fn(): Configuration => PhpUnitResults::configured('--fail-on-warning'),
        static fn(): TestResult => PhpUnitResults::result(warnings: [PhpUnitResults::issue($money)]),
    );

    expect($killers->killers())->toBe([$money->id()]);
});

it('reads the configuration and result of the run it is in, which names none while that run passes', function (): void {
    expect(IssueKillers::ofThisRun()->killers())->toBe([]);
});
