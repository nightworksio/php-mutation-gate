<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Codeception\CommandLine;
use NightWorksIO\MutationGate\Adapter\Codeception\Extension;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

const CODECEPTION_SCRIPT = '/p/vendor/bin/codecept';
const CODECEPTION_RUN = [CODECEPTION_SCRIPT, 'run', '--no-interaction', '--no-colors', '--ext', Extension::class, '--override'];

$commandLine = static fn(): CommandLine => new CommandLine(CODECEPTION_SCRIPT, '/p/vendor');
$money = static fn(WholeSuite|Group|Filter $tests): MutationRequest => MutationRequest::of(Paths::of(Path::of('src/Money.php')), $tests);
$told = static fn(string $settings): string => sprintf('extensions: config: %s: %s', Extension::class, $settings);

it('runs a mutant\'s selected tests with the extension told their file, stopped at the first that fails unless every killer is asked for', function () use ($commandLine, $money, $told): void {
    $full = $money(WholeSuite::tests())->searching(KillSearch::of(Ordering::runner(), MatrixKind::Full));

    expect($commandLine()->mutant('/g/selected.txt', $money(WholeSuite::tests())))
        ->toBe([...CODECEPTION_RUN, $told('{"selected":"/g/selected.txt"}'), '--fail-fast'])
        ->and($commandLine()->mutant('/g/selected.txt', $full))->toBe([...CODECEPTION_RUN, $told('{"selected":"/g/selected.txt"}')]);
});

it('keeps a mutant\'s run to the group or the filter that judges its unit', function () use ($commandLine, $money, $told): void {
    expect($commandLine()->mutant('/s', $money(Group::named('holds:src/Money.php')))[7])
        ->toBe($told('{"selected":"/s","in-group":"holds:src/Money.php"}'))
        ->and($commandLine()->mutant('/s', $money(Filter::matching('Money')))[7])
        ->toBe($told('{"selected":"/s","matching":"Money"}'));
});

it('writes a key\'s end inside a setting as YAML reads it back, so Codeception does not split the override there', function () use ($commandLine, $money, $told): void {
    expect($commandLine()->mutant('/s', $money(Filter::matching('a: b')))[7])->toBe($told('{"selected":"/s","matching":"a:\u0020b"}'));
});

it('passes a mutant\'s run the memory cap, which Codeception would replace with its own limit, and nothing where none caps it', function () use ($commandLine, $money): void {
    $capped = $money(WholeSuite::tests())->cappedAt(MemoryCap::of(64, MemoryUnit::Megabytes));

    expect(array_slice($commandLine()->mutant('/s', $capped), -2))->toBe(['--override', 'settings: memory_limit: 64M'])
        ->and(array_slice($commandLine()->mutant('/s', $money(WholeSuite::tests())), -1))->toBe(['--fail-fast']);
});

it('starts a run of no test as a mutant\'s own run, filtered to none', function () use ($commandLine, $told): void {
    expect($commandLine()->startUp('/s'))
        ->toBe([...CODECEPTION_RUN, $told(sprintf('{"selected":"/s","matching":%s}', json_encode(Filter::nothing()->pattern()))), '--fail-fast']);
});

it('runs a coverage run\'s tests with their map written to a file, by a group, a filter or their files', function () use ($commandLine, $told): void {
    $into = Path::of('.mutation-gate/coverage');
    $coverage = static fn(WholeSuite|Group|Filter|TestPaths $tests): array => $commandLine()->coverage(CoverageRun::of($tests, $into), '/map.php');

    expect($coverage(WholeSuite::tests()))->toBe([...CODECEPTION_RUN, $told('{"coverage-map":"/map.php","vendor-directory":"/p/vendor"}')])
        ->and($coverage(Group::named('holds:src/A.php'))[7])
        ->toBe($told('{"coverage-map":"/map.php","vendor-directory":"/p/vendor","in-group":"holds:src/A.php"}'))
        ->and($coverage(Filter::matching('A'))[7])->toBe($told('{"coverage-map":"/map.php","vendor-directory":"/p/vendor","matching":"A"}'))
        ->and($coverage(TestPaths::of(Paths::of(Path::of('tests/ATest.php'), Path::of('tests/BCest.php'))))[7])
        ->toBe($told('{"coverage-map":"/map.php","vendor-directory":"/p/vendor","declared-in":["tests/ATest.php","tests/BCest.php"]}'));
});

it('lists the suite\'s tests into a file, running none', function () use ($commandLine, $told): void {
    expect($commandLine()->listing('/l.json'))->toBe([...CODECEPTION_RUN, $told('{"listing-file":"/l.json"}')]);
});
