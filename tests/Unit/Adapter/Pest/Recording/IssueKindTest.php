<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\IssueKind;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitResults;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Framework\TestCase;

afterEach(function (): void {
    Scratch::sweep();
});

it('fails a run on a kind where its failOn option is given, or every issue fails it, and not where doNotFailOn turns it off, as PHPUnit does', function (IssueKind $kind, string $on, string $off, string $attribute): void {
    expect($kind->fails(PhpUnitResults::configured($off)))->toBeFalse()
        ->and($kind->fails(PhpUnitResults::configured($on)))->toBeTrue()
        ->and($kind->fails(PhpUnitResults::configured('--fail-on-all-issues')))->toBeTrue()
        ->and($kind->fails(PhpUnitResults::configuredOver($attribute)))->toBeTrue()
        ->and($kind->fails(PhpUnitResults::configuredOver($attribute, $off)))->toBeFalse();
})->with([
    'warning' => [IssueKind::Warning, '--fail-on-warning', '--do-not-fail-on-warning', 'failOnWarning'],
    'notice' => [IssueKind::Notice, '--fail-on-notice', '--do-not-fail-on-notice', 'failOnNotice'],
    'deprecation' => [IssueKind::Deprecation, '--fail-on-deprecation', '--do-not-fail-on-deprecation', 'failOnDeprecation'],
    'risky' => [IssueKind::Risky, '--fail-on-risky', '--do-not-fail-on-risky', 'failOnRisky'],
    'incomplete' => [IssueKind::Incomplete, '--fail-on-incomplete', '--do-not-fail-on-incomplete', 'failOnIncomplete'],
    'skipped' => [IssueKind::Skipped, '--fail-on-skipped', '--do-not-fail-on-skipped', 'failOnSkipped'],
    'PHPUnit warning' => [IssueKind::PhpunitWarning, '--fail-on-phpunit-warning', '--do-not-fail-on-phpunit-warning', 'failOnPhpunitWarning'],
    'PHPUnit notice' => [IssueKind::PhpunitNotice, '--fail-on-phpunit-notice', '--do-not-fail-on-phpunit-notice', 'failOnPhpunitNotice'],
    'PHPUnit deprecation' => [IssueKind::PhpunitDeprecation, '--fail-on-phpunit-deprecation', '--do-not-fail-on-phpunit-deprecation', 'failOnPhpunitDeprecation'],
]);

it('fails a run on deprecations where it fails on those of any trigger, and on no deprecation or notice by default', function (string $on): void {
    expect(IssueKind::Deprecation->fails(PhpUnitResults::configured($on)))->toBeTrue()
        ->and(IssueKind::Deprecation->fails(PhpUnitResults::configured()))->toBeFalse()
        ->and(IssueKind::Notice->fails(PhpUnitResults::configured()))->toBeFalse();
})->with(['--fail-on-self-deprecation', '--fail-on-direct-deprecation', '--fail-on-indirect-deprecation']);

it('reads the tests that raised each kind, by id, from the issues PHP or a test raised and the events PHPUnit keeps by test', function (): void {
    $money = PhpUnitEvents::test(TestCase::class, 'money');
    $tax = PhpUnitEvents::test(TestCase::class, 'tax');
    $skipped = PhpUnitResults::skipped();
    $incomplete = PhpUnitResults::incomplete();
    $result = PhpUnitResults::result(
        warnings: [PhpUnitResults::issue($money)],
        phpWarnings: [PhpUnitResults::issue($tax)],
        notices: [PhpUnitResults::issue($tax)],
        phpDeprecations: [PhpUnitResults::issue($money)],
        risky: [$money->id() => []],
        phpunitWarnings: [$tax->id() => []],
        phpunitNotices: [$money->id() => []],
        phpunitDeprecations: [$tax->id() => []],
        skipped: [$skipped],
        incomplete: [$incomplete],
    );
    $read = array_map(static fn(IssueKind $kind): array => $kind->testsIn($result), IssueKind::cases());

    expect(array_combine(array_map(static fn(IssueKind $kind): string => $kind->name, IssueKind::cases()), $read))->toBe([
        'Warning' => [$money->id(), $tax->id()],
        'Notice' => [$tax->id()],
        'Deprecation' => [$money->id()],
        'Risky' => [$money->id()],
        'Incomplete' => [$incomplete->test()->id()],
        'Skipped' => [$skipped->test()->id()],
        'PhpunitWarning' => [$tax->id()],
        'PhpunitNotice' => [$money->id()],
        'PhpunitDeprecation' => [$tax->id()],
    ]);
});
