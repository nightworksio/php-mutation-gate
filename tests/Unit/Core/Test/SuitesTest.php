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

it('lists the suites of the names given, and every suite where none is given', function (): void {
    expect(Suites::listed('Unit', 'Process'))->toEqual(Suites::named(SuiteName::of('Unit'), SuiteName::of('Process')))
        ->and(Suites::listed())->toEqual(Suites::all());
});

it('adds suites after its own, each once, and runs every suite where either does', function (): void {
    $unit = Suites::listed('Unit', 'Contract');

    expect($unit->and(Suites::listed('Process', 'Unit')))->toEqual(Suites::listed('Unit', 'Contract', 'Process'))
        ->and($unit->and(Suites::all()))->toEqual(Suites::all())
        ->and(Suites::all()->and($unit))->toEqual(Suites::all());
});
