<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\MutantsAtOnce;
use Pest\Mutate\Repositories\ConfigurationRepository;
use Pest\Mutate\Support\Configuration\CliConfiguration;
use Pest\Support\Container;

afterEach(function (): void {
    putenv(GateVariable::AtOnce->value);
});

it('sets the count the gate names in the command-line configuration, and nothing where it names none', function (string|false $count, array $set): void {
    $configuration = new CliConfiguration();
    MutantsAtOnce::setIn($configuration, $count);

    expect(array_intersect_key($configuration->toArray(), ['processes' => true]))->toBe($set);
})->with([
    'a count' => ['3', ['processes' => 3]],
    'one' => ['1', ['processes' => 1]],
    'none' => [false, []],
    'empty' => ['', []],
    'nought' => ['0', []],
    'not a count' => ['three', []],
    'negative' => ['-2', []],
]);

it('sets it in the command-line configuration of the repository Pest holds, over the project\'s own', function (): void {
    $held = Container::getInstance()->get(ConfigurationRepository::class);
    $repository = new ConfigurationRepository();
    $repository->globalConfiguration()->processes(9);
    Container::getInstance()->add(ConfigurationRepository::class, $repository);
    putenv(sprintf('%s=4', GateVariable::AtOnce->value));

    try {
        MutantsAtOnce::fromEnvironment();
    } finally {
        Container::getInstance()->add(ConfigurationRepository::class, $held);
    }

    expect(array_intersect_key($repository->cliConfiguration->toArray(), ['processes' => true]))->toBe(['processes' => 4])
        ->and(array_intersect_key($repository->globalConfiguration()->toArray(), ['processes' => true]))->toBe(['processes' => 9]);
});
