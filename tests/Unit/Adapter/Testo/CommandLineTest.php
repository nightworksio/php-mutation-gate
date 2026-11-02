<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Testo\CommandLine;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\Tree;

$commandLine = static fn(): CommandLine => new CommandLine(OwnScript::Testo, '/p/vendor/autoload.php');
$money = static fn(WholeSuite|Group|Filter $tests): MutationRequest => MutationRequest::of(Paths::of(Path::of('src/Money.php')), $tests);
$stops = '--mutation-gate-stop=after-the-first-failure';

it('runs a mutant\'s selected tests through the launcher, stopped after the first that fails unless every killer is asked for', function () use ($commandLine, $money, $stops): void {
    $full = $money(WholeSuite::tests())->searching(KillSearch::of(Ordering::runner(), MatrixKind::Full));

    expect($commandLine()->mutant('--mutation-gate-tests=/g/tests.txt', $money(WholeSuite::tests())))
        ->toBe([Tree::at('bin/mutation-gate-testo'), '/p/vendor/autoload.php', '--mutation-gate-tests=/g/tests.txt', $stops, '--no-ansi'])
        ->and($commandLine()->mutant('--sel', $full))->toBe([Tree::at('bin/mutation-gate-testo'), '/p/vendor/autoload.php', '--sel', '--no-ansi']);
});

it('keeps a mutant\'s run to the group or the filter that judges its unit', function () use ($commandLine, $money): void {
    expect(array_slice($commandLine()->mutant('--sel', $money(Group::named('holds:src/Money.php'))), 2, 2))
        ->toBe(['--sel', '--mutation-gate-group=holds:src/Money.php'])
        ->and(array_slice($commandLine()->mutant('--sel', $money(Filter::matching('Money'))), 2, 2))
        ->toBe(['--sel', '--mutation-gate-filter=Money']);
});

it('starts a run of no test as a mutant\'s own run, filtered to none', function () use ($commandLine, $stops): void {
    expect($commandLine()->startUp('--sel'))->toBe([
        Tree::at('bin/mutation-gate-testo'),
        '/p/vendor/autoload.php',
        '--sel',
        sprintf('--mutation-gate-filter=%s', Filter::nothing()->pattern()),
        $stops,
        '--no-ansi',
    ]);
});

it('runs a coverage run\'s tests with Testo\'s XML coverage and each test\'s time written beside it, by a group, a filter or their files', function () use ($commandLine): void {
    $into = Path::of('.mutation-gate/coverage');
    $coverage = static fn(WholeSuite|Group|Filter|TestPaths $tests): array => $commandLine()->coverage(CoverageRun::of($tests, $into), '/c/coverage-xml');

    expect($coverage(WholeSuite::tests()))->toBe([
        Tree::at('bin/mutation-gate-testo'),
        '/p/vendor/autoload.php',
        '--mutation-gate-times=/c/times.txt',
        '--no-ansi',
        '--coverage-xml=/c/coverage-xml',
    ])
        ->and(array_slice($coverage(Group::named('holds:src/A.php')), 3, 1))->toBe(['--mutation-gate-group=holds:src/A.php'])
        ->and(array_slice($coverage(Filter::matching('A')), 3, 1))->toBe(['--mutation-gate-filter=A'])
        ->and(array_slice($coverage(TestPaths::of(Paths::of(Path::of('tests/ATest.php'), Path::of('tests/BTest.php')))), 3, 2))
        ->toBe(['--mutation-gate-file=tests/ATest.php', '--mutation-gate-file=tests/BTest.php']);
});

it('keeps a coverage run and a listing to the suites asked for, and to none of them where every suite is', function () use ($commandLine): void {
    $suites = Suites::listed('unit', 'stock');
    $coverage = $commandLine()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('c'))->amongSuites($suites), '/c/coverage-xml');

    expect(array_slice($coverage, -2))->toBe(['--suite=unit', '--suite=stock'])
        ->and($commandLine()->listing($suites, '/l.json'))->toBe([
            Tree::at('bin/mutation-gate-testo'),
            '/p/vendor/autoload.php',
            '--mutation-gate-listing=/l.json',
            '--no-ansi',
            '--suite=unit',
            '--suite=stock',
        ])
        ->and($commandLine()->listing(Suites::all(), '/l.json'))
        ->toBe([Tree::at('bin/mutation-gate-testo'), '/p/vendor/autoload.php', '--mutation-gate-listing=/l.json', '--no-ansi']);
});
