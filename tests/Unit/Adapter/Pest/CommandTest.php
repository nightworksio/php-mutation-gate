<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Client;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
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

it('puts that PHP first on Pest\'s path, and hands Pest none of another run\'s, the gate\'s or an AI agent\'s variables it inherits', function (): void {
    $inherited = [
        'PARATEST',
        'TEST_TOKEN',
        'UNIQUE_TEST_TOKEN',
        'PEST_MUTATION_TESTING',
        'PEST_MUTATION_FILE',
        'INFECTION_MUTANT',
        'MUTATION_GATE_RESULTS',
        'MUTATION_GATE_CANARY',
        'AI_AGENT',
        'CLAUDECODE',
        'PAO_FORCE',
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
    $unset = [
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
    ];
    $shown = [];

    foreach (array_keys($unset) as $name) {
        $value = getenv($name);
        $shown = is_string($value) ? [...$shown, $name => $value] : $shown;
        putenv($name);
    }

    $environment = Command::pest('pest', Withheld::standard())->environment();

    foreach ($shown as $name => $value) {
        putenv(sprintf('%s=%s', $name, $value));
    }

    expect($environment)->toMatchArray($unset);
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

it('runs Pest through the client of the warm worker its slot tells it, inside the launcher where it has one, and runs anything else as it is', function (): void {
    $pest = Command::pest('vendor/bin/pest', Withheld::standard(), '--bail', 'tests')->within(Seconds::of(5.0))->with(['A' => 'b']);
    $client = [...OwnScript::PestClient->started(), '/warm', Client::SLOT];
    $forked = $pest->forkedIn('/warm');
    $launched = $pest->launchedBy('/launcher.php', '/peak')->forkedIn('/warm');

    expect($forked->arguments())->toBe([PHP_BINARY, ...$client, 'vendor/bin/pest', '--bail', 'tests'])
        ->and([$forked->deadline(), $forked->environment()['A']])->toEqual([Seconds::of(5.0), 'b'])
        ->and($launched->arguments())->toBe([PHP_BINARY, '/launcher.php', '/peak', PHP_BINARY, ...$client, 'vendor/bin/pest', '--bail', 'tests'])
        ->and($forked->forkedIn('/other')->arguments())->toBe($forked->arguments())
        ->and(Command::of('git', 'status')->forkedIn('/warm')->arguments())->toBe(['git', 'status']);
});

it('runs every test file of its suites only once it is told so, and keeps saying so through every change', function (): void {
    $plain = Command::pest('vendor/bin/pest', Withheld::standard(), '--testsuite=unit');
    $every = $plain->loadingEveryTestFile();

    expect($plain->loadsEveryTestFile())->toBeFalse()
        ->and($every->loadsEveryTestFile())->toBeTrue()
        ->and($every->arguments())->toBe($plain->arguments())
        ->and($every->with(['A' => 'b'])->loadsEveryTestFile())->toBeTrue()
        ->and($every->within(Seconds::of(5.0))->loadsEveryTestFile())->toBeTrue()
        ->and($every->launchedBy('/launcher.php', '/peak')->loadsEveryTestFile())->toBeTrue()
        ->and($every->silencedAfter(SilenceLimit::of(Seconds::of(5.0), '/progress'))->loadsEveryTestFile())->toBeTrue();
});
