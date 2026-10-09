<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('is a program with its arguments, no environment of its own and no deadline', function (): void {
    $command = Command::of('git', 'status');

    expect($command->arguments())->toBe(['git', 'status'])
        ->and($command->environment())->toBe([])
        ->and($command->deadline())->toEqual(Unlimited::time());
});

it('lists its arguments in order, however they were spread', function (): void {
    expect(Command::of(...['program' => 'git', 'command' => 'status'])->arguments())->toBe(['git', 'status']);
});

it('runs Pest on the PHP that runs the gate', function (): void {
    expect(Command::pest('vendor/pestphp/pest/bin/pest', Withheld::standard(), '--list-groups')->arguments())->toBe([PHP_BINARY, 'vendor/pestphp/pest/bin/pest', '--list-groups']);
});

it('puts that PHP first on Pest\'s path, and hands Pest none of another run\'s or the gate\'s variables it inherits', function (): void {
    $inherited = [
        'PARATEST',
        'TEST_TOKEN',
        'UNIQUE_TEST_TOKEN',
        'PEST_MUTATION_TESTING',
        'PEST_MUTATION_FILE',
        'INFECTION_MUTANT',
        'MUTATION_GATE_RESULTS',
        'MUTATION_GATE_CANARY',
    ];

    foreach ($inherited as $name) {
        putenv(sprintf('%s=inherited', $name));
    }

    $environment = Command::pest('vendor/pestphp/pest/bin/pest', Withheld::standard(), '--list-groups')->environment();

    foreach ($inherited as $name) {
        putenv($name);
    }

    expect($environment)->toMatchArray([
        ...array_fill_keys($inherited, value: false),
        'PATH' => sprintf('%s:%s', dirname(PHP_BINARY), getenv('PATH')),
    ])->and(Command::pest('pest', Withheld::standard())->with(['MUTATION_GATE_RESULTS' => '/r'])->environment())
        ->toHaveKey('MUTATION_GATE_RESULTS', '/r');
});

it('hands Pest none of the variables the gate sets for its plugin, even where the environment shows none', function (): void {
    expect(Command::pest('pest', Withheld::standard())->environment())->toMatchArray([
        'MUTATION_GATE_RESULTS' => false,
        'MUTATION_GATE_GUARD' => false,
        'MUTATION_GATE_ONLY' => false,
        'MUTATION_GATE_PRUNED' => false,
        'PEST_MUTATION_TESTING' => false,
        'PEST_MUTATION_FILE' => false,
        'PARATEST' => false,
        'TEST_TOKEN' => false,
        'UNIQUE_TEST_TOKEN' => false,
        'LARAVEL_PARALLEL_TESTING' => false,
    ]);
});

it('adds to its environment, a later value replacing an earlier one', function (): void {
    $command = Command::of('pest')->with(['A' => '1', 'B' => '2'])->with(['B' => '3']);

    expect($command->environment())->toBe(['A' => '1', 'B' => '3'])
        ->and($command->arguments())->toBe(['pest']);
});

it('takes a deadline, keeping what it runs', function (): void {
    $command = Command::of('pest')->with(['A' => '1'])->within(Seconds::of(90.0));

    expect($command->deadline())->toEqual(Seconds::of(90.0))
        ->and($command->arguments())->toBe(['pest'])
        ->and($command->environment())->toBe(['A' => '1'])
        ->and($command->within(Unlimited::time())->deadline())->toEqual(Unlimited::time());
});

it('hands Pest none of the CI\'s credentials', function (): void {
    $set = ['AWS_SECRET_ACCESS_KEY', 'ACTIONS_RUNTIME_TOKEN', 'GITHUB_TOKEN', 'SONAR_TOKEN', 'GITHUB_SHA'];
    $before = [];

    foreach ($set as $name) {
        $before[] = getenv($name);
    }

    try {
        foreach ($set as $name) {
            putenv(sprintf('%s=secret', $name));
        }

        $environment = Command::pest('pest', Withheld::standard())->environment();
    } finally {
        foreach ($set as $at => $name) {
            putenv(is_string($before[$at]) ? sprintf('%s=%s', $name, $before[$at]) : $name);
        }
    }

    expect($environment)->toMatchArray([
        'AWS_SECRET_ACCESS_KEY' => false,
        'ACTIONS_RUNTIME_TOKEN' => false,
        'GITHUB_TOKEN' => false,
        'SONAR_TOKEN' => false,
    ])->and($environment)->not->toHaveKey('GITHUB_SHA');
});

it('hands Pest none of the variables it is told to withhold, and every other', function (): void {
    $set = ['DEPLOY_KEY', 'DEPLOY_HOST', 'AWS_SECRET_ACCESS_KEY'];
    $before = [];

    foreach ($set as $name) {
        $before[] = getenv($name);
    }

    try {
        foreach ($set as $name) {
            putenv(sprintf('%s=secret', $name));
        }

        $environment = Command::pest('pest', Withheld::of('DEPLOY_*'))->environment();
    } finally {
        foreach ($set as $at => $name) {
            putenv(is_string($before[$at]) ? sprintf('%s=%s', $name, $before[$at]) : $name);
        }
    }

    expect($environment)->toMatchArray(['DEPLOY_KEY' => false, 'DEPLOY_HOST' => false])
        ->and($environment)->not->toHaveKey('AWS_SECRET_ACCESS_KEY');
});
