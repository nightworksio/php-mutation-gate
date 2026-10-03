<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

it('runs a group under coverage and leaves its map in a directory, in one process', function (): void {
    $run = CoverageRun::of(Group::named('holds:src/Kernel.php'), Path::of('.mutation-gate/coverage'));

    expect($run->tests())->toEqual(Group::named('holds:src/Kernel.php'))
        ->and($run->directory()->value())->toBe('.mutation-gate/coverage')
        ->and($run->processes())->toEqual(Processes::of(1));
});

it('runs the tests a filter names under coverage', function (): void {
    expect(CoverageRun::of(Filter::matching('KernelTest'), Path::of('held'))->tests())
        ->toEqual(Filter::matching('KernelTest'));
});

it('runs some test files under coverage', function (): void {
    $files = TestPaths::of(Paths::of(Path::of('tests/MoneyTest.php')));

    expect(CoverageRun::of($files, Path::of('.mutation-gate/coverage'))->tests())->toBe($files)
        ->and($files->files())->toEqual(Paths::of(Path::of('tests/MoneyTest.php')));
});

it('runs across as many processes as it is given, leaving the rest as it was', function (): void {
    $run = CoverageRun::of(WholeSuite::tests(), Path::of('cov'));
    $across = $run->across(Processes::of(4));

    expect($across->processes())->toEqual(Processes::of(4))
        ->and($across->tests())->toEqual(WholeSuite::tests())
        ->and($across->directory()->value())->toBe('cov')
        ->and($run->processes())->toEqual(Processes::of(1));
});

it('withholds the CI\'s credentials by default, and more where it is told, never fewer', function (): void {
    $run = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $more = $run->withholding(Withheld::of('DEPLOY_*'));

    expect($run->withheld())->toEqual(Withheld::standard())
        ->and($more->withheld())->toEqual(Withheld::standard()->and(Withheld::of('DEPLOY_*')))
        ->and($more->directory())->toBe($run->directory());
});
