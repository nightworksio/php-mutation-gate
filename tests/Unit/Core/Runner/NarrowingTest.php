<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\Fresh;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

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

it('judges by every suite by default, by the suites the config lists for the tests a run runs, and by the one --suite names over them, without narrowing for the config', function (): void {
    $unit = Suites::named(SuiteName::of('unit'));
    $listed = Narrowing::none()->amongSuites(JudgingSuites::holding($unit, Suites::named(SuiteName::of('process'))));

    expect(Narrowing::none()->suitesFor(WholeSuite::tests()))->toEqual(Suites::all())
        ->and($listed->suitesFor(WholeSuite::tests()))->toEqual($unit)
        ->and($listed->suitesFor(Group::named('holds:src/Shell.php')))->toEqual(Suites::named(SuiteName::of('unit'), SuiteName::of('process')))
        ->and($listed->toSuite(SuiteName::of('e2e'))->suitesFor(Group::named('holds:src/Shell.php')))->toEqual(Suites::named(SuiteName::of('e2e')))
        ->and($listed->isNone())->toBeTrue()
        ->and($listed->suite())->toEqual(NotGiven::value());
});

it('names the holding suites the config lists, and none by default or where --suite names one', function (): void {
    $holding = Narrowing::none()->amongSuites(JudgingSuites::holding(Suites::listed('unit'), Suites::listed('process')));

    expect($holding->holdingSuites())->toEqual(Suites::listed('process'))
        ->and(Narrowing::none()->holdingSuites())->toEqual(NotGiven::value())
        ->and($holding->toSuite(SuiteName::of('unit'))->holdingSuites())->toEqual(NotGiven::value());
});

it('runs the holding suites too where a map the gate wrote picks each mutant\'s tests, and those alone where none is listed', function (): void {
    $holding = Narrowing::none()->amongSuites(JudgingSuites::holding(Suites::listed('unit'), Suites::listed('process')));
    $judging = Narrowing::none()->amongSuites(JudgingSuites::judging(Suites::listed('unit')));

    expect($holding->mappedSuitesFor(WholeSuite::tests()))->toEqual(Suites::listed('unit', 'process'))
        ->and($judging->mappedSuitesFor(WholeSuite::tests()))->toEqual(Suites::listed('unit'))
        ->and($holding->toSuite(SuiteName::of('e2e'))->mappedSuitesFor(WholeSuite::tests()))->toEqual(Suites::listed('e2e'));
});

it('runs the holding suites for mutants the whole suite judges through a map a plan handed over, and only then', function (): void {
    $holding = Narrowing::none()->amongSuites(JudgingSuites::holding(Suites::listed('unit'), Suites::listed('process')));
    $handed = Handed::maps(Path::of('shard'), Path::of('whole'));

    expect($holding->suitesForMutants(WholeSuite::tests(), $handed))->toEqual(Suites::listed('unit', 'process'))
        ->and($holding->suitesForMutants(WholeSuite::tests(), Fresh::coverage()))->toEqual(Suites::listed('unit'))
        ->and($holding->suitesForMutants(Filter::matching('/x/'), $handed))->toEqual(Suites::listed('unit', 'process'))
        ->and($holding->toSuite(SuiteName::of('e2e'))->suitesForMutants(Group::named('slow'), $handed))->toEqual(Suites::listed('e2e'));
});
