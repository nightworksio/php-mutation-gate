<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('lists the groups without colour', function (): void {
    expect(Invocation::listingGroups())->toEqual(Command::pest('--list-groups', '--colors=never'));
});

it('runs the whole suite under coverage into a directory, as --coverage expects to find it', function (): void {
    $request = CoverageRequest::running(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))
        ->across(Processes::of(4));

    expect(Invocation::coverage($request, '/p/.mutation-gate/coverage'))->toEqual(Command::pest(
        '--parallel',
        '--processes=4',
        '--no-tia',
        '--coverage-php=/p/.mutation-gate/coverage/coverage.php',
        '--log-junit=/p/.mutation-gate/coverage/junit.xml',
    ));
});

it('runs one group under coverage', function (): void {
    $request = CoverageRequest::running(Group::named('holds:src/Held.php'), Path::of('held'));

    expect(Invocation::coverage($request, '/p/held')->arguments())->toBe([
        PHP_BINARY,
        'vendor/bin/pest',
        '--parallel',
        '--processes=1',
        '--no-tia',
        '--coverage-php=/p/held/coverage.php',
        '--log-junit=/p/held/junit.xml',
        '--group=holds:src/Held.php',
    ]);
});

it('mutates some files against the whole suite, recording to a results file, with no deadline', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php'), Path::of('src/Held.php')), WholeSuite::tests());

    expect(Invocation::mutation($request, WholeSuite::tests(), '/p/results.jsonl'))->toEqual(Command::pest(
        '--mutate',
        '--no-cache',
        '--parallel',
        '--processes=1',
        '--no-tia',
        '--colors=never',
        '--path=src/Money.php,src/Held.php',
    )->with(['MUTATION_GATE_RESULTS' => '/p/results.jsonl']));
});

it('mutates a tree less its held paths, against a group, with some mutators, by a deadline', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src')), Group::named('holds:src'))
        ->leavingOut(Paths::of(Path::of('src/Kernel.php'), Path::of('src/Boot')))
        ->onlyMutators(Mutators::named(
            'Pest\Mutate\Mutators\Arithmetic\PlusToMinus',
            'Pest\Mutate\Mutators\Logical\TrueToFalse',
        ))
        ->across(Processes::of(8))
        ->within(Seconds::of(600.0));
    $command = Invocation::mutation($request, Group::named('holds:src'), '/p/results.jsonl');

    expect($command->arguments())->toBe([
        PHP_BINARY,
        'vendor/bin/pest',
        '--mutate',
        '--no-cache',
        '--parallel',
        '--processes=8',
        '--no-tia',
        '--colors=never',
        '--path=src',
        '--ignore=src/Kernel.php,src/Boot',
        '--group=holds:src',
        '--mutator=Pest\Mutate\Mutators\Arithmetic\PlusToMinus,Pest\Mutate\Mutators\Logical\TrueToFalse',
    ])->and($command->deadline())->toEqual(Seconds::of(600.0));
});

it('leaves out one held path by name', function (): void {
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())
        ->leavingOut(Paths::of(Path::of('src/Kernel.php')));
    $command = Invocation::mutation($request, WholeSuite::tests(), '/p/r');

    expect($command->arguments())->toContain('--ignore=src/Kernel.php')
        ->and($command->deadline())->toEqual(Unlimited::time());
});
