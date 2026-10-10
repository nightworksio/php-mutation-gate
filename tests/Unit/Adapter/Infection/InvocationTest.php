<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\CoverageFor;
use NightWorksIO\MutationGate\Adapter\Infection\Invocation;
use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

function invoked(string $text): OwnConfig
{
    $config = OwnConfig::read('infection.json5', $text);

    return $config instanceof OwnConfig ? $config : throw new RuntimeException($config->why());
}

function invokedIn(): Project
{
    return Project::at(Root::of('/project'), Paths::none(), Path::of('.gate'));
}

it('lists the groups with the project\'s PHPUnit and its config', function (): void {
    expect(Invocation::listingGroups(invokedIn(), invoked('{}'))->arguments())->toBe([
        PHP_BINARY,
        '/project/vendor/bin/phpunit',
        '--configuration=/project',
        '--list-groups',
        '--colors=never',
    ]);
});

it('runs the suite under coverage into the layout Infection reads and the lines no test ran, with the project\'s own PHP options and arguments, and pcov collecting from the whole project after them', function (): void {
    $config = invoked('{"initialTestsPhpOptions": "-d pcov.directory=app", "testFrameworkExtraArgs": "--testsuite=unit"}');
    $command = Invocation::coverage(invokedIn(), $config, WholeSuite::tests(), DiskPath::of('/project/.gate/coverage'), Suites::all(), CoverageFor::Map);

    expect($command->arguments())->toBe([
        PHP_BINARY,
        '-d',
        'pcov.directory=app',
        '-d',
        'pcov.directory=/project',
        '-d',
        'pcov.exclude=~^/project/vendor/~',
        '/project/vendor/bin/phpunit',
        '--configuration=/project',
        '--coverage-xml=/project/.gate/coverage/coverage-xml',
        '--coverage-clover=/project/.gate/coverage/clover.xml',
        '--log-junit=/project/.gate/coverage/junit.xml',
        '--colors=never',
        '--testsuite=unit',
    ])->and($command->environment())->toBe(['XDEBUG_MODE' => 'coverage']);
});

it('runs only the tests that judge a held path under coverage', function (): void {
    $group = Invocation::coverage(invokedIn(), invoked('{}'), Group::named('holds:src/Kernel.php'), DiskPath::of('/c'), Suites::all(), CoverageFor::Mutation);
    $filter = Invocation::coverage(invokedIn(), invoked('{}'), Filter::matching('KernelTest'), DiskPath::of('/c'), Suites::all(), CoverageFor::Mutation);

    expect(array_slice($group->arguments(), -1))->toBe(['--group=holds:src/Kernel.php'])
        ->and(array_slice($filter->arguments(), -1))->toBe(['--filter=KernelTest']);
});

it('runs some test files under coverage, by their paths on disk, in place of the config\'s suites', function (): void {
    $files = TestPaths::of(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/Unit/HeldTest.php')));

    expect(array_slice(Invocation::coverage(invokedIn(), invoked('{}'), $files, DiskPath::of('/c'), Suites::all(), CoverageFor::Mutation)->arguments(), -2))
        ->toBe(['/project/tests/MoneyTest.php', '/project/tests/Unit/HeldTest.php']);
});

it('starts a run of no test on the config that loads none, with the project\'s arguments and none of its opening run\'s PHP options', function (): void {
    $config = invoked('{"initialTestsPhpOptions": "-d pcov.directory=app", "testFrameworkExtraArgs": "--testsuite=unit"}');

    expect(Invocation::startingUp(invokedIn(), $config, '/project/.gate/infection/start-up/phpunit.xml')->arguments())->toBe([
        PHP_BINARY,
        '/project/vendor/bin/phpunit',
        '--configuration=/project/.gate/infection/start-up/phpunit.xml',
        '--colors=never',
        '--testsuite=unit',
        '--filter=(?!)',
        '--do-not-fail-on-empty-test-suite',
    ]);
});

it('runs a control on its config with the project\'s arguments, selecting its tests by the names PHPUnit gives them', function (): void {
    $config = invoked('{"initialTestsPhpOptions": "-d pcov.directory=app", "testFrameworkExtraArgs": "--testsuite=unit"}');
    $tests = TestIds::of(
        TestId::of('Tests\\MoneyTest::testAdds'),
        TestId::of('Tests\\Tax/Test::testRate#0'),
        TestId::of('Tests\\TaxTest::testRate#384 bits'),
        TestId::of('tests/money.phpt'),
    );

    expect(Invocation::controlling(invokedIn(), $config, '/project/.gate/infection/controls/0/phpunit.xml', $tests)->arguments())->toBe([
        PHP_BINARY,
        '/project/vendor/bin/phpunit',
        '--configuration=/project/.gate/infection/controls/0/phpunit.xml',
        '--colors=never',
        '--testsuite=unit',
        '--filter=/^(?:Tests\\\\MoneyTest\:\:testAdds|Tests\\\\Tax\/Test\:\:testRate with data set \#0'
            . '|Tests\\\\TaxTest\:\:testRate with data set "384 bits"|tests\/money\.phpt)(?: with data set .*)?$/',
    ]);
});

it('runs Infection on the generated config over the coverage the gate chose, reporting every mutant and nothing of its own', function (): void {
    $command = Invocation::mutation(
        invokedIn(),
        invoked('{}'),
        WholeSuite::tests(),
        DiskPath::of('/project/.gate/coverage'),
        ProcessCount::of(4),
        ['/project/src/Money.php', '/project/src/Held.php'],
        Suites::all(),
    );

    expect($command->arguments())->toBe([
        PHP_BINARY,
        '/project/vendor/bin/infection',
        '--configuration=/project/.gate/infection/infection.json5',
        '--threads=4',
        '--no-progress',
        '--no-interaction',
        '--with-uncovered',
        '--logger-github=false',
        '--coverage=/project/.gate/coverage',
        '--skip-initial-tests',
        '/project/src/Money.php',
        '/project/src/Held.php',
    ])->and($command->environment())->toBe([]);
});

it('narrows each mutant\'s run to a group, and to the covering test methods of a #[Holds] filter, quoting every value', function (): void {
    $config = invoked('{"testFrameworkExtraArgs": "--testsuite=unit tests/Unit"}');
    $group = Invocation::mutation(invokedIn(), $config, Group::named('holds:src/Kernel.php'), DiskPath::of('/c'), ProcessCount::of(1), ['/p'], Suites::all());
    $filter = Invocation::mutation(invokedIn(), invoked('{}'), Filter::matching('Tests\\Kernel "Test"'), DiskPath::of('/c'), ProcessCount::of(1), ['/p'], Suites::all());

    expect(array_slice($group->arguments(), -2))->toBe([
        '--test-framework-extra-args=--testsuite="unit" "tests/Unit" --group="holds:src/Kernel.php"',
        '/p',
    ])->and(array_slice($filter->arguments(), -3))->toBe([
        '--test-framework-extra-args=--filter="Tests\\\\Kernel \\"Test\\""',
        '--only-covering-test-cases',
        '/p',
    ]);
});

it('keeps the coverage run and each mutant\'s run to the suites the run names, quoting them among the extra arguments', function (): void {
    $suites = Suites::named(SuiteName::of('Unit Tests'), SuiteName::of('Contract'));
    $coverage = Invocation::coverage(invokedIn(), invoked('{}'), Group::named('slow'), DiskPath::of('/c'), $suites, CoverageFor::Mutation);
    $mutation = Invocation::mutation(invokedIn(), invoked('{}'), Group::named('slow'), DiskPath::of('/c'), ProcessCount::of(1), ['/p'], $suites);

    expect(array_slice($coverage->arguments(), -2))->toBe(['--group=slow', '--testsuite=Unit Tests,Contract'])
        ->and(array_slice($mutation->arguments(), -2))->toBe([
            '--test-framework-extra-args=--group="slow" --testsuite="Unit Tests,Contract"',
            '/p',
        ]);
});

it('writes the report of the lines no test ran beside the layout only for the gate\'s map', function (): void {
    $map = Invocation::coverage(invokedIn(), invoked('{}'), WholeSuite::tests(), DiskPath::of('/c'), Suites::all(), CoverageFor::Map);
    $mutation = Invocation::coverage(invokedIn(), invoked('{}'), WholeSuite::tests(), DiskPath::of('/c'), Suites::all(), CoverageFor::Mutation);

    expect($map->arguments())->toContain('--coverage-clover=/c/clover.xml')
        ->and($mutation->arguments())->not->toContain('--coverage-clover=/c/clover.xml')
        ->and(array_values(array_diff($map->arguments(), $mutation->arguments())))->toBe(['--coverage-clover=/c/clover.xml']);
});
