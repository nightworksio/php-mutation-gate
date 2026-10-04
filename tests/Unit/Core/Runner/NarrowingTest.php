<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\SuiteName;

it('narrows nothing by default: every mutator, judged by every test', function (): void {
    $none = Narrowing::none();

    expect($none->mutators())->toEqual(Mutators::all())
        ->and($none->suite())->toEqual(NotGiven::value())
        ->and($none->isNone())->toBeTrue();
});

it('narrows to some mutators, leaving the suite as it was', function (): void {
    $narrowed = Narrowing::none()->toSuite(SuiteName::of('unit'))->toMutators(Mutators::named('Plus'));

    expect($narrowed->mutators())->toEqual(Mutators::named('Plus'))
        ->and($narrowed->suite())->toEqual(SuiteName::of('unit'))
        ->and(Narrowing::none()->toMutators(Mutators::named('Plus'))->isNone())->toBeFalse();
});

it('narrows to one suite, leaving the mutators as they were', function (): void {
    $narrowed = Narrowing::none()->toMutators(Mutators::named('Plus'))->toSuite(SuiteName::of('unit'));

    expect($narrowed->suite())->toEqual(SuiteName::of('unit'))
        ->and($narrowed->suite())->toBeInstanceOf(SuiteName::class)
        ->and($narrowed->mutators())->toEqual(Mutators::named('Plus'))
        ->and(Narrowing::none()->toSuite(SuiteName::of('unit'))->isNone())->toBeFalse()
        ->and(SuiteName::of('unit')->value())->toBe('unit');
});
