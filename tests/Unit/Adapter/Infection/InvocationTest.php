<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Invocation;
use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

function invoked(string $text): OwnConfig
{
    $config = OwnConfig::read('infection.json5', $text);

    return $config instanceof OwnConfig ? $config : throw new RuntimeException($config->why());
}

function invokedIn(): Project
{
    return Project::at('/project', Paths::none(), Path::of('.gate'));
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

it('runs the suite under coverage into the layout Infection reads, with the project\'s own PHP options and arguments', function (): void {
    $config = invoked('{"initialTestsPhpOptions": "-d pcov.directory=app", "testFrameworkExtraArgs": "--testsuite=unit"}');
    $command = Invocation::coverage(invokedIn(), $config, WholeSuite::tests(), '/project/.gate/coverage');

    expect($command->arguments())->toBe([
        PHP_BINARY,
        '-d',
        'pcov.directory=app',
        '/project/vendor/bin/phpunit',
        '--configuration=/project',
        '--coverage-xml=/project/.gate/coverage/coverage-xml',
        '--log-junit=/project/.gate/coverage/junit.xml',
        '--colors=never',
        '--testsuite=unit',
    ])->and($command->environment())->toBe(['XDEBUG_MODE' => 'coverage']);
});

it('runs only the tests that judge a held path under coverage', function (): void {
    $group = Invocation::coverage(invokedIn(), invoked('{}'), Group::named('holds:src/Kernel.php'), '/c');
    $filter = Invocation::coverage(invokedIn(), invoked('{}'), Filter::matching('KernelTest'), '/c');

    expect(array_slice($group->arguments(), -1))->toBe(['--group=holds:src/Kernel.php'])
        ->and(array_slice($filter->arguments(), -1))->toBe(['--filter=KernelTest']);
});

it('runs Infection on the generated config over the coverage the gate chose, reporting every mutant and nothing of its own', function (): void {
    $command = Invocation::mutation(
        invokedIn(),
        invoked('{}'),
        WholeSuite::tests(),
        '/project/.gate/coverage',
        Processes::of(4),
        ['/project/src/Money.php', '/project/src/Held.php'],
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
    $group = Invocation::mutation(invokedIn(), $config, Group::named('holds:src/Kernel.php'), '/c', Processes::of(1), ['/p']);
    $filter = Invocation::mutation(invokedIn(), invoked('{}'), Filter::matching('Tests\\Kernel "Test"'), '/c', Processes::of(1), ['/p']);

    expect(array_slice($group->arguments(), -2))->toBe([
        '--test-framework-extra-args=--testsuite="unit" "tests/Unit" --group="holds:src/Kernel.php"',
        '/p',
    ])->and(array_slice($filter->arguments(), -3))->toBe([
        '--test-framework-extra-args=--filter="Tests\\\\Kernel \\"Test\\""',
        '--only-covering-test-cases',
        '/p',
    ]);
});
