<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Plugin;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Guard;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Naming;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

/**
 * Runs with only these of the variables the plugin reads set, and the rest
 * unset. Where this suite runs in a mutant's own process, as the gate's own
 * run on this package starts it, they name that process's results, mutated
 * copy and order, which no test here may write to or read.
 *
 * @template T of object
 *
 * @param  array<string, string> $set
 * @param  Closure(): T          $run
 * @return T
 */
function withPluginVariables(array $set, Closure $run): object
{
    $read = [
        ...array_map(static fn(GateVariable $variable): string => $variable->value, GateVariable::cases()),
        Recorder::MUTANT,
        Recorder::MUTATED,
    ];

    return Environment::during([...array_fill_keys($read, null), ...$set], $run);
}

/**
 * A plugin booted with only these of the variables it reads set.
 *
 * @param array<string, string> $set
 */
function pluginBootedWith(array $set): Plugin
{
    return withPluginVariables($set, static function (): Plugin {
        $plugin = new Plugin();
        $plugin->boot();

        return $plugin;
    });
}

/**
 * A process's arguments as the plugin hands them on, with only these of the
 * variables it reads set.
 *
 * @param  array<string, string> $set
 * @param  array<int, string>    $arguments
 * @return list<string>
 */
function argumentsHandledWith(array $set, array $arguments): array
{
    $handled = withPluginVariables($set, static fn(): ArrayObject => new ArrayObject(new Plugin()->handleArguments($arguments)));

    return array_values($handled->getArrayCopy());
}

it('records, guards, names no killer and no test, and orders nothing until Pest boots it', function (): void {
    expect(new Plugin()->recorder())->toBe(Off::Recording)
        ->and(new Plugin()->guard())->toBe(Off::Guarding)
        ->and(new Plugin()->killers())->toBe(Off::NamingKillers)
        ->and(new Plugin()->naming())->toBe(Off::NamingTests)
        ->and(new Plugin()->seeder())->toBe(Off::Ordering);
});

it('names the tests of a run that lists them for the adapter, writing the names when the run ends', function (): void {
    $names = sprintf('%s/names.json', Scratch::untilExit());
    $plugin = pluginBootedWith([GateVariable::Names->value => $names]);
    $plugin->finish();
    $named = json_decode((string) file_get_contents($names), associative: true);

    expect($plugin->naming())->toBeInstanceOf(Naming::class)
        ->and($plugin->guard())->toBe(Off::Guarding)
        ->and(is_array($named) ? array_column($named, 'description', 'test') : [])
        ->toHaveKey(sprintf('P\\Tests\\Unit\\Adapter\\Pest\\PluginTest::%s', '__pest_evaluable_it_names_the_tests_of_a_run_that_lists_them_for_the_adapter__writing_the_names_when_the_run_ends'));
});

it('leaves the arguments of a process that is no mutant\'s as they are', function (): void {
    expect(argumentsHandledWith([], [2 => 'vendor/bin/pest', 5 => '--cache-directory', 6 => '/v/.temp']))
        ->toBe(['vendor/bin/pest', '--cache-directory', '/v/.temp']);
});

it('drops --bail from a mutant\'s own process under a full kill matrix', function (): void {
    $arguments = [2 => 'vendor/bin/pest', 5 => '--bail', 6 => '--parallel'];
    $full = argumentsHandledWith([Recorder::MUTATED => __FILE__, GateVariable::KillMatrix->value => 'full'], $arguments);
    $first = argumentsHandledWith(
        [Recorder::MUTATED => __FILE__, GateVariable::KillMatrix->value => 'first-killer'],
        $arguments,
    );

    expect($full)->toBe(['vendor/bin/pest', '--parallel'])
        ->and($first)->toBe(['vendor/bin/pest', '--bail', '--parallel']);
});

it('guards a run the adapter starts on one mutant, writing what it saw when the run ends', function (): void {
    $guard = sprintf('%s/guard.json', Scratch::untilExit());
    $plugin = pluginBootedWith([Recorder::MUTANT => __FILE__, GateVariable::Guard->value => $guard]);

    new Plugin()->finish();
    $unwatched = is_file($guard);
    $plugin->finish();

    expect($plugin->guard())->toBeInstanceOf(Guard::class)
        ->and($plugin->recorder())->toBe(Off::Recording)
        ->and($unwatched)->toBeFalse()
        ->and(json_decode((string) file_get_contents($guard), associative: true))->toMatchArray(['before' => true, 'loaded' => true]);
});

it('records nothing and names no killer when Pest boots it outside the adapter\'s runs', function (): void {
    $plugin = pluginBootedWith([]);

    expect($plugin->recorder())->toBe(Off::Recording)
        ->and($plugin->killers())->toBe(Off::NamingKillers);
});

it('records where the adapter asks when Pest boots it', function (): void {
    $plugin = pluginBootedWith([GateVariable::Results->value => '/r/results.jsonl']);

    expect($plugin->recorder())->toBeInstanceOf(Recorder::class);
});

it('is the Pest plugin this package\'s composer.json lists', function (): void {
    $manifest = json_decode((string) file_get_contents(Tree::at('composer.json')), associative: true);

    expect($manifest)->toHaveKey('extra.pest.plugins', [Plugin::class]);
});

it('loads the bridges to the registered mutators the adapter wrote, as it boots', function (): void {
    $bridges = sprintf('%s/bridges.php', Scratch::untilExit());
    file_put_contents($bridges, "<?php\n\nfunction pluginLoadedTheBridges(): bool\n{\n    return true;\n}\n");
    pluginBootedWith([GateVariable::Mutators->value => $bridges]);

    expect(function_exists('pluginLoadedTheBridges'))->toBeTrue();
});
