<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;

it('names no suite where it runs every one', function (): void {
    expect(Suites::all()->isAll())->toBeTrue()
        ->and(Suites::all()->joined())->toBe('')
        ->and([...Suites::all()])->toBe([]);
});

it('names its suites in the order given, joined by commas as --testsuite takes them', function (): void {
    $suites = Suites::named(SuiteName::of('Unit'), SuiteName::of('Contract Tests'), SuiteName::of('Plugins'));

    expect($suites->isAll())->toBeFalse()
        ->and($suites->joined())->toBe('Unit,Contract Tests,Plugins')
        ->and([...$suites])->toEqual([SuiteName::of('Unit'), SuiteName::of('Contract Tests'), SuiteName::of('Plugins')]);
});
