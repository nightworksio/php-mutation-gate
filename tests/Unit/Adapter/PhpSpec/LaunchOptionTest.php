<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\LaunchOption;
use NightWorksIO\MutationGate\Core\NotGiven;

it('sets each option by its name, an equals sign and its value, which it reads back', function (LaunchOption $option): void {
    $argument = $option->with('a=b');

    expect($argument)->toBe(sprintf('%s=a=b', $option->value))
        ->and(LaunchOption::setBy($argument))->toBe($option)
        ->and($option->valueIn($argument))->toBe('a=b')
        ->and($option->valueIn($option->with('')))->toBe('');
})->with([LaunchOption::Examples, LaunchOption::Group, LaunchOption::Filter, LaunchOption::Spec, LaunchOption::Coverage, LaunchOption::Listing]);

it('names options the launcher takes off before PhpSpec reads its own', function (): void {
    expect(array_map(static fn(LaunchOption $option): string => $option->value, LaunchOption::cases()))->toBe([
        '--mutation-gate-examples',
        '--mutation-gate-group',
        '--mutation-gate-filter',
        '--mutation-gate-spec',
        '--mutation-gate-coverage',
        '--mutation-gate-listing',
    ]);
});

it('leaves PhpSpec\'s own arguments, and an option named without its value, to PhpSpec', function (string $argument): void {
    expect(LaunchOption::setBy($argument))->toBeInstanceOf(NotGiven::class);
})->with(['run', '--format=dot', '--mutation-gate-examples', 'x--mutation-gate-group=a', '--mutation-gate-other=a']);
