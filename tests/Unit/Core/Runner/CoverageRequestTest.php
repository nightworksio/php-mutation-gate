<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

it('asks a group to run under coverage and leave its map in a directory, in one process', function (): void {
    $request = CoverageRequest::running(Group::named('holds:src/Kernel.php'), Path::of('.mutation-gate/coverage'));

    expect($request->runs())->toBeTrue()
        ->and($request->tests())->toEqual(Group::named('holds:src/Kernel.php'))
        ->and($request->directory()->value())->toBe('.mutation-gate/coverage')
        ->and($request->processes())->toEqual(Processes::of(1));
});

it('asks the tests a filter names to run under coverage', function (): void {
    expect(CoverageRequest::running(Filter::matching('KernelTest'), Path::of('held'))->tests())
        ->toEqual(Filter::matching('KernelTest'));
});

it('asks for the whole suite\'s map another job left in a directory', function (): void {
    $request = CoverageRequest::reading(Path::of('build/coverage'));

    expect($request->runs())->toBeFalse()
        ->and($request->tests())->toEqual(WholeSuite::tests())
        ->and($request->directory()->value())->toBe('build/coverage')
        ->and($request->processes())->toEqual(Processes::of(1));
});

it('runs across as many processes as it is given, leaving the rest as it was', function (): void {
    $request = CoverageRequest::running(WholeSuite::tests(), Path::of('cov'));
    $across = $request->across(Processes::of(4));

    expect($across->processes())->toEqual(Processes::of(4))
        ->and($across->runs())->toBeTrue()
        ->and($across->tests())->toEqual(WholeSuite::tests())
        ->and($across->directory()->value())->toBe('cov')
        ->and($request->processes())->toEqual(Processes::of(1));
});

it('withholds the CI\'s credentials by default, and more where it is told, never fewer', function (): void {
    $running = CoverageRequest::running(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $reading = CoverageRequest::reading(Path::of('.mutation-gate/coverage'));
    $more = $running->withholding(Withheld::of('DEPLOY_*'));

    expect($running->withheld())->toEqual(Withheld::standard())
        ->and($reading->withheld())->toEqual(Withheld::standard())
        ->and($more->withheld())->toEqual(Withheld::standard()->and(Withheld::of('DEPLOY_*')))
        ->and($more->directory())->toBe($running->directory());
});
