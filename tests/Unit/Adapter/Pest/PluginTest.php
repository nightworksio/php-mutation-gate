<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Plugin;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Guard;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Naming;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('records, guards, names no killer and no test, and orders nothing until Pest boots it', function (): void {
    expect(new Plugin()->recorder())->toBe(Off::Recording)
        ->and(new Plugin()->guard())->toBe(Off::Guarding)
        ->and(new Plugin()->killers())->toBe(Off::NamingKillers)
        ->and(new Plugin()->naming())->toBe(Off::NamingTests)
        ->and(new Plugin()->seeder())->toBe(Off::Ordering);
});

it('names the tests of a run that lists them for the adapter, writing the names when the run ends', function (): void {
    $before = getenv('MUTATION_GATE_NAMES');
    $names = sprintf('%s/names.json', Scratch::untilExit());
    $plugin = new Plugin();

    try {
        putenv(sprintf('MUTATION_GATE_NAMES=%s', $names));
        $plugin->boot();
    } finally {
        putenv(is_string($before) ? sprintf('MUTATION_GATE_NAMES=%s', $before) : 'MUTATION_GATE_NAMES');
    }

    $plugin->finish();
    $named = json_decode((string) file_get_contents($names), associative: true);

    expect($plugin->naming())->toBeInstanceOf(Naming::class)
        ->and($plugin->guard())->toBe(Off::Guarding)
        ->and(is_array($named) ? array_column($named, 'description', 'test') : [])
        ->toHaveKey(sprintf('P\\Tests\\Unit\\Adapter\\Pest\\PluginTest::%s', '__pest_evaluable_it_names_the_tests_of_a_run_that_lists_them_for_the_adapter__writing_the_names_when_the_run_ends'));
});

it('leaves the arguments of a process that is no mutant\'s as they are', function (): void {
    expect(new Plugin()->handleArguments([2 => 'vendor/bin/pest', 5 => '--cache-directory', 6 => '/v/.temp']))
        ->toBe(['vendor/bin/pest', '--cache-directory', '/v/.temp']);
});

it('guards a run the adapter starts on one mutant, writing what it saw when the run ends', function (): void {
    $variables = ['PEST_MUTATION_TESTING' => getenv('PEST_MUTATION_TESTING'), 'MUTATION_GATE_GUARD' => false];
    $guard = sprintf('%s/guard.json', Scratch::untilExit());
    $plugin = new Plugin();

    try {
        putenv(sprintf('PEST_MUTATION_TESTING=%s', __FILE__));
        putenv(sprintf('MUTATION_GATE_GUARD=%s', $guard));
        $plugin->boot();
    } finally {
        foreach ($variables as $name => $value) {
            putenv(is_string($value) ? sprintf('%s=%s', $name, $value) : $name);
        }
    }

    new Plugin()->finish();
    $unwatched = is_file($guard);
    $plugin->finish();

    expect($plugin->guard())->toBeInstanceOf(Guard::class)
        ->and($plugin->recorder())->toBe(Off::Recording)
        ->and($unwatched)->toBeFalse()
        ->and(json_decode((string) file_get_contents($guard), associative: true))->toMatchArray(['before' => true, 'loaded' => true]);
});

it('records nothing and names no killer when Pest boots it outside the adapter\'s runs', function (): void {
    // Outside the adapter's runs none of its variables is set, even where this
    // suite itself runs inside one, as the gate's own run on this package does.
    $names = [...array_map(static fn(GateVariable $variable): string => $variable->value, GateVariable::cases()), Recorder::MUTATED];
    $before = [];
    $plugin = new Plugin();

    try {
        foreach ($names as $name) {
            $before[$name] = getenv($name);
            putenv($name);
        }

        $plugin->boot();
    } finally {
        foreach ($before as $name => $value) {
            putenv(is_string($value) ? sprintf('%s=%s', $name, $value) : $name);
        }
    }

    expect($plugin->recorder())->toBe(Off::Recording)
        ->and($plugin->killers())->toBe(Off::NamingKillers);
});

it('records where the adapter asks when Pest boots it', function (): void {
    $mutant = getenv('PEST_MUTATION_TESTING');
    $plugin = new Plugin();

    try {
        putenv('PEST_MUTATION_TESTING');
        putenv('MUTATION_GATE_RESULTS=/r/results.jsonl');
        $plugin->boot();
    } finally {
        putenv('MUTATION_GATE_RESULTS');
        putenv(is_string($mutant) ? sprintf('PEST_MUTATION_TESTING=%s', $mutant) : 'PEST_MUTATION_TESTING');
    }

    expect($plugin->recorder())->toBeInstanceOf(Recorder::class);
});

it('is the Pest plugin this package\'s composer.json lists', function (): void {
    $manifest = json_decode((string) file_get_contents(Tree::at('composer.json')), associative: true);

    expect($manifest)->toHaveKey('extra.pest.plugins', [Plugin::class]);
});
