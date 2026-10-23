<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\CommandLine;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

$commandLine = static fn(): CommandLine => new CommandLine('/gate/bin/mutation-gate-phpspec', '/p/vendor/autoload.php');
$run = ['run', '--no-interaction', '--no-code-generation', '--no-rerun', '--format=dot'];
$money = static fn(WholeSuite|Group|Filter $tests): MutationRequest => MutationRequest::of(Paths::of(Path::of('src/Money.php')), $tests);

it('runs a mutant\'s selected examples through the launcher, stopped at the first that fails unless every killer is asked for', function () use ($commandLine, $run, $money): void {
    $full = $money(WholeSuite::tests())->searching(KillSearch::of(Ordering::runner(), MatrixKind::Full));

    expect($commandLine()->mutant('--mutation-gate-examples=/g/examples.txt', $money(WholeSuite::tests())))
        ->toBe(['/gate/bin/mutation-gate-phpspec', '/p/vendor/autoload.php', '--mutation-gate-examples=/g/examples.txt', ...$run, '--stop-on-failure'])
        ->and($commandLine()->mutant('--sel', $full))->toBe(['/gate/bin/mutation-gate-phpspec', '/p/vendor/autoload.php', '--sel', ...$run]);
});

it('keeps a mutant\'s run to the group or the filter that judges its unit', function () use ($commandLine, $money): void {
    expect(array_slice($commandLine()->mutant('--sel', $money(Group::named('holds:src/Money.php'))), 2, 2))
        ->toBe(['--sel', '--mutation-gate-group=holds:src/Money.php'])
        ->and(array_slice($commandLine()->mutant('--sel', $money(Filter::matching('Money'))), 2, 2))
        ->toBe(['--sel', '--mutation-gate-filter=Money']);
});

it('starts a run of no example as a mutant\'s own run, filtered to none', function () use ($commandLine, $run): void {
    expect($commandLine()->startUp('--sel'))->toBe([
        '/gate/bin/mutation-gate-phpspec',
        '/p/vendor/autoload.php',
        '--sel',
        sprintf('--mutation-gate-filter=%s', Filter::nothing()->pattern()),
        ...$run,
        '--stop-on-failure',
    ]);
});

it('runs a coverage run\'s examples with their map written to a file, by a group, a filter or their spec files', function () use ($commandLine, $run): void {
    $into = Path::of('.mutation-gate/coverage');
    $coverage = static fn(WholeSuite|Group|Filter|TestPaths $tests): array => $commandLine()->coverage(CoverageRun::of($tests, $into), '/map.php');

    expect($coverage(WholeSuite::tests()))->toBe(['/gate/bin/mutation-gate-phpspec', '/p/vendor/autoload.php', '--mutation-gate-coverage=/map.php', ...$run])
        ->and(array_slice($coverage(Group::named('holds:src/A.php')), 3, 1))->toBe(['--mutation-gate-group=holds:src/A.php'])
        ->and(array_slice($coverage(Filter::matching('A')), 3, 1))->toBe(['--mutation-gate-filter=A'])
        ->and(array_slice($coverage(TestPaths::of(Paths::of(Path::of('spec/ASpec.php'), Path::of('spec/BSpec.php')))), 3, 2))
        ->toBe(['--mutation-gate-spec=spec/ASpec.php', '--mutation-gate-spec=spec/BSpec.php']);
});

it('lists the suite\'s examples into a file, running none', function () use ($commandLine, $run): void {
    expect($commandLine()->listing('/l.json'))->toBe(['/gate/bin/mutation-gate-phpspec', '/p/vendor/autoload.php', '--mutation-gate-listing=/l.json', ...$run]);
});
